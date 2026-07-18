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
require_once __DIR__ . '/../credit_limit_helper.php';
require_once __DIR__ . '/../ensure_tab_payments_mixed_migration.php';
ensureTabPaymentsAllowsMixedPaymentMethod($db);
require_once __DIR__ . '/../tab_balance_helper.php';
require_once __DIR__ . '/../cashback_accounting_helper.php';
require_once __DIR__ . '/../ensure_orders_gratuity_columns.php';
require_once __DIR__ . '/../ensure_tab_gratuity_columns.php';
ensureTabPrepaidBalanceColumn($db);
ensureTabVoidMarkColumns($db);
ensureTabGratuityColumns($db);

$tabGratuitySettings = tab_gratuity_settings($db);
$tabGratuityFeatureEnabled = $tabGratuitySettings['feature_enabled'];
$tabGratuityPercent = $tabGratuitySettings['percent'];

// Fetch the hide_available_quantity setting
try {
    $settingStmt = $db->query("SELECT hide_available_quantity FROM product_settings LIMIT 1");
    $setting = $settingStmt->fetch(PDO::FETCH_ASSOC);
    $hide_available_quantity = $setting['hide_available_quantity'] ?? 0; // Default to 0 if not set
} catch (PDOException $e) {
    $hide_available_quantity = 0; // Default to 0 on error
}

$productsForTabTips = [];
try {
    $productsForTabTips = $db->query("SELECT id, name, price, quantity FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $productsForTabTips = [];
}

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
    handle_tab_void_mark_post_request($db);

    if (isset($_POST['toggle_tab_gratuity'])) {
        $tabId = intval($_POST['tab_id'] ?? 0);
        if ($tabId <= 0) {
            $_SESSION['error'] = 'Invalid tab';
            header('Location: credit-tabs');
            exit();
        }
        if (!$tabGratuityFeatureEnabled) {
            $_SESSION['error'] = 'Gratuity is not configured in settings';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        $tabCheck = $db->prepare("SELECT id, status FROM tabs WHERE id = ?");
        $tabCheck->execute([$tabId]);
        $tabRow = $tabCheck->fetch(PDO::FETCH_ASSOC);
        if (!$tabRow || ($tabRow['status'] ?? '') !== 'open') {
            $_SESSION['error'] = 'Gratuity can only be changed on an open tab';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        $enabled = isset($_POST['gratuity_enabled']) && (int) $_POST['gratuity_enabled'] === 1 ? 1 : 0;
        tab_set_gratuity_enabled_on_tab($db, $tabId, (bool) $enabled);
        $_SESSION['success'] = $enabled ? 'Gratuity added to tab balance' : 'Gratuity removed from tab balance';
        header('Location: view-tab.php?id=' . $tabId);
        exit();
    }

    if (isset($_POST['delete_item_id'])) {
        // Delete tab item
        $itemId = intval($_POST['delete_item_id']);
        $tabId = intval($_POST['tab_id']);
        $itemStmt = $db->prepare("SELECT tab_id, quantity, product_name FROM tab_items WHERE id = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            $db->beginTransaction();
            try {
                // Restore product quantity to stock (catalog products only; prepayment/postpaid lines are not inventory)
                if (!is_tab_non_inventory_tab_line_name($item['product_name'])) {
                    $restoreStmt = $db->prepare("UPDATE products SET quantity = quantity + ? WHERE name = ?");
                    $restoreStmt->execute([$item['quantity'], $item['product_name']]);
                    
                    $currentDate = date('Y-m-d');
                    $resolveProductStmt = $db->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
                    $resolveProductStmt->execute([$item['product_name']]);
                    if ($resolveProductStmt->fetchColumn()) {
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
                    }
                }
                
                // Delete related tab_item_payments first (cascade should handle this, but being explicit)
                $deletePaymentsStmt = $db->prepare("DELETE FROM tab_item_payments WHERE tab_item_id = ?");
                $deletePaymentsStmt->execute([$itemId]);
                
                // Delete the item
                $deleteStmt = $db->prepare("DELETE FROM tab_items WHERE id = ?");
                $deleteStmt->execute([$itemId]);
                
                // Recalculate tab balance from scratch
                recalculateTabBalance($db, $tabId);
                
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
        
        $itemStmt = $db->prepare("SELECT tab_id, price, product_name FROM tab_items WHERE id = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            if (is_tab_prepayment_line_name($item['product_name'])) {
                $_SESSION['error'] = 'Prepayment credit lines cannot be edited. Remove the line and add a new amount if needed.';
                header('Location: view-tab.php?id=' . $tabId);
                exit();
            }
            $db->beginTransaction();
            try {
                // Use original price (price cannot be changed if item has payments)
                $originalPrice = floatval($item['price']);
                
                // Check if there are payments on this item
                $paymentCheckStmt = $db->prepare("SELECT COUNT(*) FROM tab_item_payments WHERE tab_item_id = ?");
                $paymentCheckStmt->execute([$itemId]);
                $hasPayments = $paymentCheckStmt->fetchColumn() > 0;
                
                if ($hasPayments) {
                    // If item has payments, price cannot be changed - use original price
                    $updateStmt = $db->prepare("UPDATE tab_items SET quantity = ? WHERE id = ?");
                    $updateStmt->execute([$newQuantity, $itemId]);
                } else {
                    // No payments, can update both quantity and price if needed
                    // But for now, we only allow quantity changes (price stays same)
                    $updateStmt = $db->prepare("UPDATE tab_items SET quantity = ? WHERE id = ?");
                    $updateStmt->execute([$newQuantity, $itemId]);
                }
                
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
    } elseif (isset($_POST['edit_tab_name'])) {
        $tabId = intval($_POST['tab_id']);
        $newTabName = trim($_POST['new_tab_name']);
        
        if (empty($newTabName)) {
            $_SESSION['error'] = 'Tab name cannot be empty';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        try {
            $updateStmt = $db->prepare("UPDATE tabs SET tab_name = ? WHERE id = ?");
            $updateStmt->execute([$newTabName, $tabId]);
            
            $_SESSION['success'] = 'Tab name updated successfully';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed to update tab name: ' . $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
    } elseif (isset($_POST['add_tab_prepayment'])) {
        if (!can_add_tab_prepay_postpaid_lines_from_session()) {
            $_SESSION['error'] = 'You do not have permission to add prepayment credit to a tab';
            header('Location: view-tab.php?id=' . intval($_POST['tab_id'] ?? 0));
            exit();
        }
        $tabId = intval($_POST['tab_id'] ?? 0);
        $creditAmount = floatval($_POST['prepayment_amount'] ?? 0);
        if ($tabId <= 0 || $creditAmount <= 0) {
            $_SESSION['error'] = 'Enter a valid tab and a prepayment amount greater than zero';
            header('Location: ' . ($tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs'));
            exit();
        }
        $tabCheck = $db->prepare("SELECT id, status FROM tabs WHERE id = ?");
        $tabCheck->execute([$tabId]);
        $tabRow = $tabCheck->fetch(PDO::FETCH_ASSOC);
        if (!$tabRow || ($tabRow['status'] ?? '') !== 'open') {
            $_SESSION['error'] = 'Tab not found or not open';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        $cashierUsername = $_SESSION['username'] ?? getUsernameById($_SESSION['user_id'] ?? null);
        $unitCredit = -1 * abs($creditAmount);
        try {
            $ins = $db->prepare("INSERT INTO tab_items (tab_id, product_name, quantity, price, added_by) VALUES (?, ?, 1, ?, ?)");
            $ins->execute([$tabId, TAB_PREPAYMENT_LINE_NAME, $unitCredit, $cashierUsername]);
            recalculateTabBalance($db, $tabId);
            $_SESSION['success'] = 'Prepayment credit of N$' . number_format(abs($unitCredit), 2) . ' added as a tab line (no stock movement).';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = 'Could not add prepayment line: ' . $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
    } elseif (isset($_POST['add_tab_postpaid'])) {
        if (!can_add_tab_prepay_postpaid_lines_from_session()) {
            $_SESSION['error'] = 'You do not have permission to add postpaid charges to a tab';
            header('Location: view-tab.php?id=' . intval($_POST['tab_id'] ?? 0));
            exit();
        }
        $tabId = intval($_POST['tab_id'] ?? 0);
        $chargeAmount = floatval($_POST['postpaid_amount'] ?? 0);
        if ($tabId <= 0 || $chargeAmount <= 0) {
            $_SESSION['error'] = 'Enter a valid tab and a postpaid amount greater than zero';
            header('Location: ' . ($tabId > 0 ? 'view-tab.php?id=' . $tabId : 'credit-tabs'));
            exit();
        }
        $tabCheck = $db->prepare("SELECT id, status FROM tabs WHERE id = ?");
        $tabCheck->execute([$tabId]);
        $tabRow = $tabCheck->fetch(PDO::FETCH_ASSOC);
        if (!$tabRow || ($tabRow['status'] ?? '') !== 'open') {
            $_SESSION['error'] = 'Tab not found or not open';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        $cashierUsername = $_SESSION['username'] ?? getUsernameById($_SESSION['user_id'] ?? null);
        $unitCharge = abs($chargeAmount);
        try {
            $ins = $db->prepare("INSERT INTO tab_items (tab_id, product_name, quantity, price, added_by) VALUES (?, ?, 1, ?, ?)");
            $ins->execute([$tabId, TAB_POSTPAID_LINE_NAME, $unitCharge, $cashierUsername]);
            recalculateTabBalance($db, $tabId);
            $_SESSION['success'] = 'Postpaid charge of N$' . number_format($unitCharge, 2) . ' added as a tab line (no stock movement).';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = 'Could not add postpaid line: ' . $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
    } elseif (isset($_POST['transfer_to_credit_sale'])) {
        // Transfer tab to credit sale - Only admins or managers can transfer tabs
        $allowedRoles = ['admin', 'manager'];
        if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles)) {
            $_SESSION['error'] = 'Only admins or managers can transfer tabs to credit sales';
            header('Location: view-tab.php?id=' . intval($_POST['tab_id']));
            exit();
        }
        
        $tabId = intval($_POST['tab_id']);
        $creditorId = intval($_POST['creditor_id']);
        $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        
        // Get tab details
        $tabStmt = $db->prepare("SELECT * FROM tabs WHERE id = ?");
        $tabStmt->execute([$tabId]);
        $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tab) {
            $_SESSION['error'] = 'Tab not found';
            header('Location: credit-tabs');
            exit();
        }
        
        if ($tab['status'] === 'closed') {
            $_SESSION['error'] = 'Cannot transfer a closed tab';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        // Check creditor exists and is active
        $creditorStmt = $db->prepare("SELECT * FROM creditors WHERE id = ? AND active = 1");
        $creditorStmt->execute([$creditorId]);
        $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$creditor) {
            $_SESSION['error'] = 'Creditor not found or inactive';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        // Get unpaid tab items
        $tabItemsStmt = $db->prepare("
            SELECT ti.*, 
                   COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) as paid_amount,
                   (ti.quantity * ti.price) as item_total
            FROM tab_items ti
            WHERE ti.tab_id = ?
                AND (
                    (ti.quantity * ti.price) < 0
                    OR COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) < (ti.quantity * ti.price)
                )
        ");
        $tabItemsStmt->execute([$tabId]);
        $unpaidItems = $tabItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($unpaidItems)) {
            $_SESSION['error'] = 'No unpaid items to transfer';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        // Calculate total amount and prepare items for credit sale
        $totalAmount = 0;
        $creditSaleItems = [];
        
        foreach ($unpaidItems as $item) {
            $itemTotal = floatval($item['item_total']);
            $paidAmount = floatval($item['paid_amount']);
            $unpaidAmount = $itemTotal - $paidAmount;
            
            if ($unpaidAmount <= 0.01) continue;
            
            // Calculate unpaid quantity (proportional to unpaid amount)
            $paymentRatio = $unpaidAmount / $itemTotal;
            $unpaidQuantity = intval(round($item['quantity'] * $paymentRatio));
            
            // Ensure at least 1 if there's unpaid amount
            if ($unpaidQuantity < 1 && $unpaidAmount > 0.01) {
                $unpaidQuantity = 1;
            }
            
            if ($unpaidQuantity <= 0) continue;
            
            $unitPrice = floatval($item['price']);
            $totalAmount += ($unpaidQuantity * $unitPrice);
            
            $creditSaleItems[] = [
                'name' => $item['product_name'],
                'quantity' => $unpaidQuantity,
                'price' => $unpaidQuantity * $unitPrice, // Total price for this quantity
                'unit_price' => $unitPrice
            ];
        }
        
        if ($totalAmount <= 0) {
            $_SESSION['error'] = 'No unpaid balance to transfer';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }

        try {
            assertCreditSaleWithinLimit($db, $creditorId, $totalAmount);
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        $db->beginTransaction();
        try {
            // Create credit sale record
            $cashierUsername = $_SESSION['username'] ?? 'Unknown';
            $saleStmt = $db->prepare("INSERT INTO credit_sales (creditor_id, total_amount, due_date, created_at, cashier_id, payment_status) 
                                     VALUES (?, ?, ?, ?, ?, 'unpaid')");
            $saleStmt->execute([
                $creditorId,
                $totalAmount,
                $dueDate,
                date('Y-m-d H:i:s'),
                $cashierUsername
            ]);
            $saleId = $db->lastInsertId();
            
            // Prepare statements for credit sale items
            $itemStmt = $db->prepare("INSERT INTO credit_sale_items (sale_id, product_name, quantity, price, buying_price) 
                                     VALUES (?, ?, ?, ?, ?)");
            $stmtGetProductInfo = $db->prepare("SELECT buying_price FROM products WHERE name = ?");
            
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
            
            // Process each unpaid item
            foreach ($creditSaleItems as $item) {
                if (is_tab_postpaid_line_name($item['name'])) {
                    $buyingPrice = null;
                } else {
                    $stmtGetProductInfo->execute([$item['name']]);
                    $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
                    if (!$productInfo) {
                        throw new Exception('Product not found: ' . $item['name']);
                    }
                    $buyingPrice = $productInfo['buying_price'] ?? null;
                }
                
                // Create credit sale item (store per-item price)
                $itemStmt->execute([
                    $saleId,
                    $item['name'],
                    $item['quantity'],
                    $item['unit_price'], // Store per-item price
                    $buyingPrice
                ]);
                
                if (!is_tab_postpaid_line_name($item['name'])) {
                    $stmtEnsureDailySummary->execute([$currentDate, $item['name']]);
                    $stmtUpdateDailySummary->execute([
                        $currentDate, $item['name'],
                        $currentDate, $item['name'],
                        $currentDate, $item['name'],
                        $currentDate, $item['name'],
                        $currentDate, $item['name'], $item['quantity'],
                        $currentDate, $item['name']
                    ]);
                }
            }
            
            // Delete tab-related records
            // Delete tab_item_payments first
            $deleteTabItemPaymentsStmt = $db->prepare("DELETE FROM tab_item_payments WHERE tab_item_id IN (SELECT id FROM tab_items WHERE tab_id = ?)");
            $deleteTabItemPaymentsStmt->execute([$tabId]);
            
            // Delete tab_payments
            $deleteTabPaymentsStmt = $db->prepare("DELETE FROM tab_payments WHERE tab_id = ?");
            $deleteTabPaymentsStmt->execute([$tabId]);
            
            // Delete tab_items
            $deleteTabItemsStmt = $db->prepare("DELETE FROM tab_items WHERE tab_id = ?");
            $deleteTabItemsStmt->execute([$tabId]);
            
            // Delete the tab
            $deleteTabStmt = $db->prepare("DELETE FROM tabs WHERE id = ?");
            $deleteTabStmt->execute([$tabId]);
            
            // Update creditor balance
            $updateCreditorStmt = $db->prepare("UPDATE creditors SET balance = balance + ? WHERE id = ?");
            $updateCreditorStmt->execute([$totalAmount, $creditorId]);
            
            $db->commit();
            $_SESSION['success'] = 'Tab transferred to credit sale successfully. Credit Sale ID: ' . $saleId;
            header('Location: credit-tabs');
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to transfer tab: ' . $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
    } elseif (isset($_POST['payment_amount'])) {
        // Make payment on tab - treat as sale and decrease quantities
        $tabId = intval($_POST['tab_id']);
        $amount = floatval($_POST['payment_amount']);
        $tipAmount = floatval($_POST['tip_amount'] ?? 0);
        $paymentMethod = $_POST['payment_method'];
        $transactionRef = $_POST['transaction_ref'] ?? '';
        $walletProvider = $_POST['wallet_provider'] ?? '';
        $cashAmount = floatval($_POST['cash_amount'] ?? 0);
        $eftAmount = floatval($_POST['eft_amount'] ?? 0);
        $cashTenderedInput = floatval($_POST['cash_tendered'] ?? 0);
        
        if ($amount <= 0) {
            $_SESSION['error'] = 'Payment amount must be greater than zero';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }

        if ($tipAmount < 0) {
            $_SESSION['error'] = 'Tip amount cannot be negative';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        // Validate mixed payment
        $isMixedPayment = $paymentMethod === 'mixed';
        if ($isMixedPayment && ($cashAmount + $eftAmount) + 0.001 < $amount) {
            $_SESSION['error'] = 'Cash + EFT must be at least the payment amount';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }

        $cashBackPost = floatval($_POST['cash_back_amount'] ?? 0);
        if ($cashBackPost < 0) {
            $_SESSION['error'] = 'Cash back amount cannot be negative';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        $willBeEftPayment = ($paymentMethod === 'eft' || ($isMixedPayment && $eftAmount > 0));
        if ($cashBackPost > 0.001 && !$willBeEftPayment) {
            $_SESSION['error'] = 'Cash back is only allowed when paying by EFT or mixed with an EFT portion.';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        $cashReceivedForOrder = 0;
        if ($paymentMethod === 'cash') {
            $cashReceivedForOrder = $cashTenderedInput > 0 ? $cashTenderedInput : $amount;
            if ($cashReceivedForOrder + 0.001 < $amount) {
                $_SESSION['error'] = 'Cash tendered must be at least the payment amount';
                header('Location: view-tab.php?id=' . $tabId);
                exit();
            }
        } elseif ($isMixedPayment) {
            $cashReceivedForOrder = $cashAmount;
        }
        
        // Transaction reference is optional for EFT payments
        
        // Check tab balance and get original cashier who opened the tab
        $tabStmt = $db->prepare("SELECT current_balance, cashier_id, gratuity_enabled, COALESCE(gratuity_paid, 0) AS gratuity_paid FROM tabs WHERE id = ?");
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
        $operatorUsername = getUsernameById($_SESSION['user_id'] ?? null);
        if ($operatorUsername === 'Unknown' || $operatorUsername === '') {
            $operatorUsername = $_SESSION['username'] ?? 'Unknown';
        }
        
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

        try {
            $db->exec("ALTER TABLE tab_payments ADD COLUMN tip_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            // Column might already exist, ignore error
        }

        try {
            $db->exec('ALTER TABLE tab_payments ADD COLUMN cash_back_amount DECIMAL(10,2) NOT NULL DEFAULT 0');
        } catch (PDOException $e) {
            // Column might already exist, ignore error
        }
        
        // Get unpaid tab items (ordered by oldest first - FIFO)
        $tabItemsStmt = $db->prepare("
            SELECT ti.*, (ti.quantity * ti.price) as item_total,
                   COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) as paid_amount
            FROM tab_items ti
            WHERE ti.tab_id = ?
                AND (
                    (ti.quantity * ti.price) < 0
                    OR COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) < (ti.quantity * ti.price)
                )
            ORDER BY ti.added_at ASC
        ");
        $tabItemsStmt->execute([$tabId]);
        $unpaidItems = $tabItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate which items to pay for (FIFO). Any remainder becomes advance prepayment on the tab.
        $remainingPayment = $amount;
        $itemsToPay = [];
        
        foreach ($unpaidItems as $item) {
            if ($remainingPayment <= 0) {
                break;
            }
            
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
                    'paid_quantity' => intval($item['quantity'] * $paymentRatio)
                ];
                
                $remainingPayment -= $paymentForThisItem;
            }
        }
        
        $gratuityPaymentPortion = 0.0;
        if ($remainingPayment > 0.01) {
            ensureTabGratuityColumns($db);
            $gratuityOwed = tab_gratuity_remaining($db, $tabId, $tab);
            if ($gratuityOwed > 0.01) {
                $gratuityPaymentPortion = min($remainingPayment, $gratuityOwed);
                $remainingPayment -= $gratuityPaymentPortion;
            }
        }
        
        $prepaidToAdd = ($remainingPayment > 0.01) ? $remainingPayment : 0;
        
        if (empty($itemsToPay) && $prepaidToAdd <= 0.01 && $gratuityPaymentPortion <= 0.01) {
            $_SESSION['error'] = 'Nothing to apply — add line items first, or enter an amount for advance payment.';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }

        tab_log_payment_allocation($db, $tabId, $amount, $itemsToPay, $prepaidToAdd);
        
        ensureTabPrepaidBalanceColumn($db);
        ensure_orders_gratuity_columns($db);

        $db->beginTransaction();
        try {
            $gratuityForOrder = round(max(0.0, $tipAmount) + $gratuityPaymentPortion, 2);
            $gratuityPctForOrder = ($gratuityPaymentPortion > 0.001 && tab_is_gratuity_enabled_for_tab($tab))
                ? $tabGratuityPercent
                : null;
            $orderStmt = $db->prepare(
                'INSERT INTO orders (total, cash_received, created_at, cashier_id, gratuity_amount, gratuity_percent_applied, gratuity_included_in_total) VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            $orderStmt->execute([
                $amount,
                $cashReceivedForOrder,
                date('Y-m-d H:i:s'),
                $cashierUsername,
                $gratuityForOrder,
                $gratuityPctForOrder,
            ]);
            $orderId = $db->lastInsertId();
            
            // Prepare statements for daily stock summary
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
            
            $resolveProductStmt = $db->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
            
            $currentDate = date('Y-m-d');
            
            // Process each item being paid for
            foreach ($itemsToPay as $itemPayment) {
                $paidQty = $itemPayment['paid_quantity'];
                
                if ($paidQty > 0) {
                    // Add to order_items
                    $itemPrice = $itemPayment['price'] * $paidQty;
                    $stmtOrderItems->execute([
                        $orderId,
                        $itemPayment['product_name'],
                        $paidQty,
                        $itemPrice
                    ]);
                    
                    $resolveProductStmt->execute([$itemPayment['product_name']]);
                    if ($resolveProductStmt->fetchColumn()) {
                        $stmtEnsureDailySummary->execute([$currentDate, $itemPayment['product_name']]);
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
            }
            
            // Insert payment record
            $paymentTimestamp = date('Y-m-d H:i:s');
            $paymentStmt = $db->prepare("INSERT INTO tab_payments (tab_id, amount, tip_amount, cash_back_amount, payment_method, transaction_ref, wallet_provider, cashier_id, order_id, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $paymentStmt->execute([$tabId, $amount, $tipAmount, round($cashBackPost, 2), $paymentMethod, $transactionRef, $walletProvider, $cashierUsername, $orderId, $paymentTimestamp]);
            $paymentId = $db->lastInsertId();
            
            // Link payments to line items (omit when this payment is 100% advance prepayment)
            if (!empty($itemsToPay)) {
                $linkStmt = $db->prepare("INSERT INTO tab_item_payments (tab_item_id, payment_id, amount) VALUES (?, ?, ?)");
                foreach ($itemsToPay as $itemPayment) {
                    $linkStmt->execute([$itemPayment['item_id'], $paymentId, $itemPayment['payment_amount']]);
                }
            }
            
            if ($prepaidToAdd > 0.01) {
                $updPrepaid = $db->prepare("UPDATE tabs SET prepaid_balance = COALESCE(prepaid_balance, 0) + ? WHERE id = ?");
                $updPrepaid->execute([$prepaidToAdd, $tabId]);
            }

            if ($gratuityPaymentPortion > 0.01) {
                $updGratuityPaid = $db->prepare('UPDATE tabs SET gratuity_paid = COALESCE(gratuity_paid, 0) + ? WHERE id = ?');
                $updGratuityPaid->execute([round($gratuityPaymentPortion, 2), $tabId]);
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
                if ($itemTotal <= 0.01) {
                    continue;
                }
                // Use small tolerance for floating point comparison
                if ($totalPaid >= ($itemTotal - 0.01)) {
                    // Delete related payments first
                    $deleteItemPaymentsStmt = $db->prepare("DELETE FROM tab_item_payments WHERE tab_item_id = ?");
                    $deleteItemPaymentsStmt->execute([$item['id']]);
                    // Then delete the item
                    $deletePaidStmt->execute([$item['id']]);
                }
            }
            
            // Recalculate tab balance from scratch (more accurate than manual update)
            recalculateTabBalance($db, $tabId);
            
            // Approve the tab by setting pending_manager_approval to 0 after payment
            $approveStmt = $db->prepare("UPDATE tabs SET pending_manager_approval = 0 WHERE id = ?");
            $approveStmt->execute([$tabId]);
            
            // Auto-close tab when fully paid; close time is the payment time.
            $tabBalanceStmt = $db->prepare("SELECT current_balance FROM tabs WHERE id = ?");
            $tabBalanceStmt->execute([$tabId]);
            $tabBalanceAfterPayment = floatval($tabBalanceStmt->fetchColumn());
            if ($tabBalanceAfterPayment <= 0.01) {
                $closeTabStmt = $db->prepare("UPDATE tabs SET status = 'closed', closed_at = ?, closed_by = ? WHERE id = ?");
                $closeTabStmt->execute([$paymentTimestamp, $cashierUsername, $tabId]);
            }
            
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

            if ($cashBackPost > 0.001) {
                $cbTs = date('Y-m-d H:i:s');
                $wpCb = trim((string) $walletProvider) !== '' ? trim((string) $walletProvider) : 'Customer';
                $refCb = trim((string) $transactionRef);
                $descCb = 'Cash Back - Tab ' . $tabId . ($wpCb !== 'Customer' ? ' - ' . $wpCb : '');
                recordCashBackAccounting($db, round($cashBackPost, 2), $operatorUsername, $cbTs, $descCb, true, $wpCb, $refCb);
            }
            
            $db->commit();
            $successMsg = 'Payment processed successfully and product quantities updated';
            if ($prepaidToAdd > 0.01) {
                $successMsg .= ' N$' . number_format($prepaidToAdd, 2) . ' recorded as advance payment (prepaid on tab).';
            }
            if ($cashBackPost > 0.001) {
                $successMsg .= ' Cash back N$' . number_format($cashBackPost, 2) . ' recorded.';
            }
            $_SESSION['success'] = $successMsg;
            header('Location: view-tab.php?id=' . $tabId . '&payment_success=1&order_id=' . $orderId);
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to process payment: ' . $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
    } elseif (isset($_POST['void_tab_id'])) {
        // Void tab - Only admins or managers can void tabs
        $allowedRoles = ['admin', 'manager'];
        if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles)) {
            $_SESSION['error'] = 'Only admins or managers can void tabs';
            header('Location: view-tab.php?id=' . intval($_POST['void_tab_id']));
            exit();
        }
        
        $tabId = intval($_POST['void_tab_id']);
        
        // Get tab details
        $tabStmt = $db->prepare("SELECT * FROM tabs WHERE id = ?");
        $tabStmt->execute([$tabId]);
        $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tab) {
            $_SESSION['error'] = 'Tab not found';
            header('Location: credit-tabs');
            exit();
        }
        
        if ($tab['status'] === 'closed') {
            $_SESSION['error'] = 'Cannot void a closed tab';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        $db->beginTransaction();
        try {
            $currentDate = date('Y-m-d');
            
            // Step 1: Get all orders linked to this tab via tab_payments
            $ordersStmt = $db->prepare("SELECT DISTINCT order_id FROM tab_payments WHERE tab_id = ? AND order_id IS NOT NULL");
            $ordersStmt->execute([$tabId]);
            $orderIds = $ordersStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Step 2: Collect all products and quantities that need to be restored
            // Products from order_items (fully paid items that were removed from tab_items)
            $productsToRestore = [];
            
            if (!empty($orderIds)) {
                $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
                $orderItemsStmt = $db->prepare("SELECT product_name, SUM(quantity) as total_quantity FROM order_items WHERE order_id IN ($placeholders) GROUP BY product_name");
                $orderItemsStmt->execute($orderIds);
                $orderItems = $orderItemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($orderItems as $orderItem) {
                    $productName = $orderItem['product_name'];
                    $quantity = intval($orderItem['total_quantity']);
                    if (!isset($productsToRestore[$productName])) {
                        $productsToRestore[$productName] = 0;
                    }
                    $productsToRestore[$productName] += $quantity;
                }
            }
            
            // Step 3: Get all remaining tab_items (unpaid or partially paid) and add to restore list
            $tabItemsStmt = $db->prepare("SELECT product_name, SUM(quantity) as total_quantity FROM tab_items WHERE tab_id = ? GROUP BY product_name");
            $tabItemsStmt->execute([$tabId]);
            $tabItems = $tabItemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($tabItems as $item) {
                $productName = $item['product_name'];
                $quantity = intval($item['total_quantity']);
                if (!isset($productsToRestore[$productName])) {
                    $productsToRestore[$productName] = 0;
                }
                $productsToRestore[$productName] += $quantity;
            }
            
            // Step 4: Restore all quantities
            if (!empty($productsToRestore)) {
                $restoreStmt = $db->prepare("UPDATE products SET quantity = quantity + ? WHERE name = ?");
                $stmtUpdateDailySummary = $db->prepare("
                    UPDATE daily_stock_summary 
                    SET sold_quantity = CASE 
                        WHEN sold_quantity - ? < 0 THEN 0 
                        ELSE sold_quantity - ? 
                    END
                    WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)
                ");
                $stmtEnsureDailySummary = $db->prepare("
                    INSERT OR IGNORE INTO daily_stock_summary 
                    (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                    VALUES (?, (SELECT id FROM products WHERE name = ?), 0, 0, 0, 0, 0)
                ");
                
                foreach ($productsToRestore as $productName => $quantity) {
                    if ($quantity > 0) {
                        // Restore quantity to products
                        $restoreStmt->execute([$quantity, $productName]);
                        
                        // Ensure daily stock summary exists
                        $stmtEnsureDailySummary->execute([$currentDate, $productName]);
                        
                        // Update daily stock summary (decrease sold_quantity)
                        $stmtUpdateDailySummary->execute([$quantity, $quantity, $currentDate, $productName]);
                    }
                }
            }
            
            // Step 5: Delete related payment records for orders
            if (!empty($orderIds)) {
                $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
                
                // Delete eft_payments
                $deleteEftStmt = $db->prepare("DELETE FROM eft_payments WHERE order_id IN ($placeholders)");
                $deleteEftStmt->execute($orderIds);
                
                // Delete mixed_payments
                $deleteMixedStmt = $db->prepare("DELETE FROM mixed_payments WHERE order_id IN ($placeholders)");
                $deleteMixedStmt->execute($orderIds);
                
                // Delete order_items
                $deleteOrderItemsStmt = $db->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
                $deleteOrderItemsStmt->execute($orderIds);
                
                // Delete orders
                $deleteOrdersStmt = $db->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
                $deleteOrdersStmt->execute($orderIds);
            }
            
            // Step 6: Delete all tab-related records
            // Delete tab_item_payments
            $deleteTabItemPaymentsStmt = $db->prepare("DELETE FROM tab_item_payments WHERE tab_item_id IN (SELECT id FROM tab_items WHERE tab_id = ?)");
            $deleteTabItemPaymentsStmt->execute([$tabId]);
            
            // Delete tab_payments
            $deleteTabPaymentsStmt = $db->prepare("DELETE FROM tab_payments WHERE tab_id = ?");
            $deleteTabPaymentsStmt->execute([$tabId]);
            
            // Delete tab_items
            $deleteTabItemsStmt = $db->prepare("DELETE FROM tab_items WHERE tab_id = ?");
            $deleteTabItemsStmt->execute([$tabId]);
            
            // Step 7: Delete the tab
            $deleteTabStmt = $db->prepare("DELETE FROM tabs WHERE id = ?");
            $deleteTabStmt->execute([$tabId]);
            
            $db->commit();
            $_SESSION['success'] = 'Tab voided successfully. All items have been restored to stock.';
            header('Location: credit-tabs');
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to void tab: ' . $e->getMessage();
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

if (($viewTab['status'] ?? '') === 'open') {
    recalculateTabBalance($db, $tabId);
    $viewTabStmt->execute([$tabId]);
    $viewTab = $viewTabStmt->fetch(PDO::FETCH_ASSOC);
}

// Add username to tab
$viewTab['opened_by_username'] = getUsernameById($viewTab['cashier_id']);

// Recalculate balance to ensure accuracy (fixes any inconsistencies)
try {
    recalculateTabBalance($db, $tabId);
    // Refetch tab to get updated balance
    $viewTabStmt->execute([$tabId]);
    $viewTab = $viewTabStmt->fetch(PDO::FETCH_ASSOC);
    $viewTab['opened_by_username'] = getUsernameById($viewTab['cashier_id']);
} catch (Exception $e) {
    // Log error but continue - balance might be slightly off but page should still work
    error_log("Error recalculating balance for tab $tabId: " . $e->getMessage());
}

$tabPrepaid = floatval($viewTab['prepaid_balance'] ?? 0);
$tabGratuityAmount = tab_compute_gratuity_amount($db, $tabId, $viewTab);
$tabGratuityRemaining = tab_gratuity_remaining($db, $tabId, $viewTab);
$tabGratuityEnabled = tab_is_gratuity_enabled_for_tab($viewTab);
$defaultPaymentModalAmount = ($viewTab['current_balance'] > 0.01) ? (float)$viewTab['current_balance'] : 100.0;

// Fetch tab items (only unpaid items) - using GROUP BY to ensure uniqueness
$tabItemsStmt = $db->prepare("
    SELECT ti.*, ti.added_by,
           COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) as paid_amount,
           (ti.quantity * ti.price) as item_total,
           (SELECT image_url FROM products WHERE name = ti.product_name LIMIT 1) as product_image
    FROM tab_items ti
    WHERE ti.tab_id = ?
        AND (
            (ti.quantity * ti.price) < 0
            OR COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) < (ti.quantity * ti.price)
        )
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
$tabItems = array_values(array_filter($tabItems, function ($item) {
    return !is_tab_legacy_gratuity_line_name($item['product_name'] ?? '');
}));

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

// Fetch VAT settings from business info
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
$vatInclusive = $businessInfo['vat_inclusive'] ?? 'exclusive';
$vatRate = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;

// Fetch order data for receipt printing if payment was just made
$orderDataForReceipt = null;
if (isset($_GET['payment_success']) && isset($_GET['order_id'])) {
    $orderId = intval($_GET['order_id']);
    
    // Fetch order details
    $orderStmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        // Fetch order items
        $orderItemsStmt = $db->prepare("SELECT product_name as name, quantity, price FROM order_items WHERE order_id = ?");
        $orderItemsStmt->execute([$orderId]);
        $orderItems = $orderItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch payment details
        $paymentStmt = $db->prepare("SELECT * FROM tab_payments WHERE order_id = ? ORDER BY payment_date DESC LIMIT 1");
        $paymentStmt->execute([$orderId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch EFT payment details if exists
        $eftStmt = $db->prepare("SELECT * FROM eft_payments WHERE order_id = ? LIMIT 1");
        $eftStmt->execute([$orderId]);
        $eftPayment = $eftStmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch mixed payment details if exists
        $mixedStmt = $db->prepare("SELECT * FROM mixed_payments WHERE order_id = ? LIMIT 1");
        $mixedStmt->execute([$orderId]);
        $mixedPayment = $mixedStmt->fetch(PDO::FETCH_ASSOC);
        
        // Format items for receipt
        $formattedItems = [];
        foreach ($orderItems as $item) {
            $formattedItems[] = [
                'name' => $item['name'],
                'quantity' => intval($item['quantity']),
                'price' => floatval($item['price'])
            ];
        }
        
        // Prepare receipt data
        // cashier_id in orders table now stores username, but resolve it just in case
        $cashierForReceipt = $order['cashier_id'] ?? 'Unknown';
        if (is_numeric($cashierForReceipt)) {
            $cashierForReceipt = getUsernameById($cashierForReceipt);
        }
        
        $orderDataForReceipt = [
            'order_id' => $orderId,
            'items' => $formattedItems,
            'cashier_username' => $cashierForReceipt,
            'total' => floatval($order['total']),
            'cash_received' => floatval($order['cash_received'] ?? 0),
            'created_at' => $order['created_at'],
            'vat_inclusive' => $vatInclusive,
            'vat_rate' => $vatRate,
            'gratuity_amount' => floatval($order['gratuity_amount'] ?? 0),
            'gratuity_percent_applied' => $order['gratuity_percent_applied'] ?? null,
            'gratuity_included_in_total' => isset($order['gratuity_included_in_total']) ? (int) $order['gratuity_included_in_total'] : 1,
        ];
        
        // Add payment method information
        if ($payment) {
            $orderDataForReceipt['payment_method'] = $payment['payment_method'];
            $orderDataForReceipt['transaction_ref'] = $payment['transaction_ref'] ?? '';
            $orderDataForReceipt['wallet_provider'] = $payment['wallet_provider'] ?? '';
            $orderDataForReceipt['tips'] = floatval($payment['tip_amount'] ?? 0);
            $orderDataForReceipt['payment_date'] = $payment['payment_date'] ?? '';
            
            if ($payment['payment_method'] === 'mixed' && $mixedPayment) {
                $orderDataForReceipt['cash_amount'] = floatval($mixedPayment['cash_amount']);
                $orderDataForReceipt['eft_amount'] = floatval($mixedPayment['eft_amount']);
                $orderDataForReceipt['eft_transaction_ref'] = $mixedPayment['eft_transaction_ref'] ?? '';
                $orderDataForReceipt['eft_wallet_provider'] = $mixedPayment['eft_wallet_provider'] ?? '';
            } else if ($payment['payment_method'] === 'eft' && $eftPayment) {
                $orderDataForReceipt['transaction_ref'] = $eftPayment['transaction_ref'] ?? '';
                $orderDataForReceipt['wallet_provider'] = $eftPayment['wallet_provider'] ?? '';
            }
        }

        $tipForReceipt = floatval($orderDataForReceipt['tips'] ?? 0);
        $orderDataForReceipt['gratuity'] = max(0.0, round(floatval($order['gratuity_amount'] ?? 0) - $tipForReceipt, 2));
        if ($orderDataForReceipt['gratuity'] > 0.001 && ($orderDataForReceipt['gratuity_percent_applied'] ?? null) === null && $tabGratuityEnabled) {
            $orderDataForReceipt['gratuity_percent_applied'] = $tabGratuityPercent;
        }
        
        // Add tab information
        $orderDataForReceipt['tab_id'] = $tabId;
        $orderDataForReceipt['tab_name'] = $viewTab['tab_name'];
        $orderDataForReceipt['closed_at'] = $viewTab['closed_at'] ?? '';
        if ($viewTab['creditor_name']) {
            $orderDataForReceipt['creditor_name'] = $viewTab['creditor_name'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($viewTab['tab_name']) ?> - Tab Details</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <script src="../lucide.js"></script>
    <script src="../sweetalert2@11.js"></script>
    <?= tab_pos_confirm_script_tag('../') ?>
    <?php $kbAssetPrefix = '../'; include __DIR__ . '/../includes/kioskboard_payment.php'; ?>
    <!-- Load sendToPrinter function from receipt.php -->
    <script src="../receipt.php?js=true"></script>

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
        
        /* Ensure SweetAlert2 modals always appear above sidebar */
        .swal2-container {
            z-index: 10002 !important;
        }
        
        .swal2-popup {
            z-index: 10003 !important;
        }
        
        .swal2-backdrop-show {
            z-index: 10001 !important;
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
    <?= tab_view_header_styles_html() ?>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="content flex-1 lg:ml-64">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="w-full p-4 lg:p-6">
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
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <h1 class="text-2xl font-bold text-gray-900">
                                        <?= htmlspecialchars($viewTab['tab_name']) ?>
                                    </h1>
                                    <button onclick="openEditTabNameModal(<?= $viewTab['id'] ?>, '<?= htmlspecialchars($viewTab['tab_name'], ENT_QUOTES) ?>')" 
                                            class="text-gray-400 hover:text-blue-600 transition-colors p-1" 
                                            title="Edit tab name">
                                        <i data-lucide="pencil" class="w-4 h-4"></i>
                                    </button>
                                </div>
                                <p class="text-sm text-gray-500">
                                    <?php if ($viewTab['creditor_name']): ?>
                                        <span class="mr-3 inline-flex items-center"><i data-lucide="user" class="w-4 h-4 mr-1"></i><?= htmlspecialchars($viewTab['creditor_name']) ?></span>
                                    <?php endif; ?>
                                    <span class="mr-3 inline-flex items-center"><i data-lucide="user-circle" class="w-4 h-4 mr-1"></i>Opened by: <?= htmlspecialchars($viewTab['opened_by_username'] ?? 'Unknown') ?></span>
                                    <?= tab_status_badges_html($viewTab, true) ?>
                                </p>
                            </div>
                        </div>
                        <!-- Desktop Header -->
                        <div class="hidden lg:block">
                            <div class="flex items-center gap-2 mb-1">
                                <h1 class="text-3xl font-bold text-gray-900">
                                    <?= htmlspecialchars($viewTab['tab_name']) ?>
                                </h1>
                                <button onclick="openEditTabNameModal(<?= $viewTab['id'] ?>, '<?= htmlspecialchars($viewTab['tab_name'], ENT_QUOTES) ?>')" 
                                        class="text-gray-400 hover:text-blue-600 transition-colors p-1" 
                                        title="Edit tab name">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                </button>
                            </div>
                            <p class="text-sm text-gray-500">
                                <?php if ($viewTab['creditor_name']): ?>
                                    <span class="mr-3 inline-flex items-center"><i data-lucide="user" class="w-4 h-4 mr-1"></i><?= htmlspecialchars($viewTab['creditor_name']) ?></span>
                                <?php endif; ?>
                                <span class="mr-3 inline-flex items-center"><i data-lucide="user-circle" class="w-4 h-4 mr-1"></i>Opened by: <?= htmlspecialchars($viewTab['opened_by_username'] ?? 'Unknown') ?></span>
                                <?= tab_status_badges_html($viewTab, true) ?>
                            </p>
                        </div>
                        <a href="credit-tabs" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>Back
                        </a>
                    </div>

                    <!-- Key Info Bar -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4 mb-6">
                        <div class="tab-key-info-row">
                            <div class="flex items-end gap-3 sm:gap-4 shrink-0 tab-key-info-balance">
                                <div class="shrink-0">
                                    <p class="text-xs text-gray-500 mb-1">Current Balance</p>
                                    <p class="text-2xl font-bold <?= $viewTab['current_balance'] > 0 ? 'text-red-600' : ($viewTab['current_balance'] < 0 ? 'text-teal-600' : 'text-gray-600') ?>">
                                        N$<?= number_format($viewTab['current_balance'], 2) ?>
                                    </p>
                                    <?php if ($tabPrepaid > 0.01): ?>
                                    <p class="text-xs text-teal-700 mt-1">Advance on tab (prepaid): N$<?= number_format($tabPrepaid, 2) ?></p>
                                    <?php endif; ?>
                                    <?php if (($viewTab['status'] ?? '') !== 'open' && $tabGratuityEnabled && $tabGratuityAmount > 0.001): ?>
                                    <p class="text-xs text-gray-600 mt-1">Gratuity (<?= htmlspecialchars(rtrim(rtrim(number_format($tabGratuityPercent, 2, '.', ''), '0'), '.')) ?>%): N$<?= number_format($tabGratuityAmount, 2) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($viewTab['creditor_phone']): ?>
                                <div class="border-l border-gray-200 pl-5 shrink-0">
                                    <p class="text-xs text-gray-500 mb-1">Contact</p>
                                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($viewTab['creditor_phone']) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($viewTab['status'] === 'open'): ?>
                            <div class="flex flex-nowrap items-center gap-1.5 shrink-0 ml-auto">
                                <?= tab_gratuity_toggle_action_html($viewTab, $tabGratuityFeatureEnabled, $tabGratuityEnabled, $tabGratuityPercent, $tabGratuityAmount, $tabGratuityRemaining) ?>
                                <?php if ($viewTab['current_balance'] > 0): ?>
                                <button onclick="openTabPrintChoice(<?= $viewTab['id'] ?>, '<?= htmlspecialchars($viewTab['tab_name'], ENT_QUOTES) ?>', <?= number_format($viewTab['current_balance'], 2, '.', '') ?>)"
                                    class="tab-header-action border border-gray-300 text-gray-700 bg-white hover:bg-gray-50" title="Print">
                                    <i data-lucide="printer" class="w-3.5 h-3.5 shrink-0"></i>Print
                                </button>
                                <button onclick="openSplitBillModal(<?= $viewTab['id'] ?>, <?= number_format($viewTab['current_balance'], 2, '.', '') ?>)"
                                    class="tab-header-action border border-amber-300 text-amber-800 bg-amber-50 hover:bg-amber-100" title="Split bill">
                                    <i data-lucide="users" class="w-3.5 h-3.5 shrink-0"></i>Split
                                </button>
                                <?php endif; ?>
                                <?php if ($viewTab['current_balance'] > 0.01): ?>
                                <button onclick="openPaymentModal(<?= $viewTab['id'] ?>, <?= number_format($defaultPaymentModalAmount, 2, '.', '') ?>)"
                                    class="tab-header-action border border-transparent text-white bg-teal-600 hover:bg-teal-700" title="Pay">
                                    <i data-lucide="wallet" class="w-3.5 h-3.5 shrink-0"></i>Pay
                                </button>
                                <?php endif; ?>
                                <?php if ($viewTab['current_balance'] > 0): ?>
                                <button onclick="openTransferToCreditSaleModal(<?= $viewTab['id'] ?>, <?= number_format($viewTab['current_balance'], 2, '.', '') ?>, <?= $viewTab['creditor_id'] ?? 'null' ?>)"
                                    class="tab-header-action border border-indigo-300 text-indigo-800 bg-indigo-50 hover:bg-indigo-100" title="Transfer to credit">
                                    <i data-lucide="arrow-right-left" class="w-3.5 h-3.5 shrink-0"></i>Transfer
                                </button>
                                <button type="button" onclick="<?= htmlspecialchars('openTabStandaloneTipModal(' . (int)$viewTab['id'] . ', ' . json_encode($viewTab['tab_name'] ?? '', JSON_UNESCAPED_UNICODE) . ')', ENT_QUOTES, 'UTF-8') ?>"
                                    class="tab-header-action border border-amber-200 text-amber-900 bg-amber-50/90 hover:bg-amber-100" title="Add tip">
                                    <i data-lucide="coins" class="w-3.5 h-3.5 shrink-0"></i>Tip
                                </button>
                                <?php endif; ?>
                                <?= tab_prepay_postpaid_action_html($viewTab) ?>
                                <?php if ($viewTab['current_balance'] > 0 || tab_is_marked_for_void($viewTab)): ?>
                                <button onclick="openVoidTabModal(<?= $viewTab['id'] ?>, '<?= htmlspecialchars($viewTab['tab_name'], ENT_QUOTES) ?>')"
                                    class="tab-header-action border border-red-300 text-red-800 bg-red-50 hover:bg-red-100" title="Void tab">
                                    <i data-lucide="x-circle" class="w-3.5 h-3.5 shrink-0"></i>Void
                                </button>
                                <?php endif; ?>
                                <?= tab_void_mark_action_html($viewTab) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Products in Tab -->
                <div class="flex flex-col mb-6">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:divide-gray-700 dark:divide-gray-700 bg-white">
                                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                                    <h3 class="text-lg font-semibold text-gray-700">
                                        <i data-lucide="shopping-cart" class="w-5 h-5 mr-2 text-gray-600 inline-block"></i>Products
                                    </h3>
                                </div>
                                <div class="overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Product</th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Qty</th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Total</th>
                                                <?php if ($viewTab['status'] === 'open'): ?>
                                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php if (empty($tabItems)): ?>
                                    <tr>
                                        <td colspan="<?= $viewTab['status'] === 'open' ? '5' : '4' ?>" class="p-6 text-center text-sm text-gray-500">No products in this tab</td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $itemsTotal = 0;
                                    foreach($tabItems as $item): 
                                        $isPrepayLine = is_tab_prepayment_line_name($item['product_name']);
                                        $isPostpaidLine = is_tab_postpaid_line_name($item['product_name']);
                                        $itemTotal = $item['quantity'] * $item['price'];
                                        $itemsTotal += $itemTotal;
                                    ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors <?= $isPrepayLine ? 'bg-teal-50/60 dark:bg-teal-950/20' : ($isPostpaidLine ? 'bg-amber-50/70 dark:bg-amber-950/20' : '') ?>">
                                            <td class="px-6 py-4 whitespace-nowrap" data-label="Product">
                                                <div class="flex items-center gap-3">
                                                    <div class="relative w-10 h-10 rounded-lg overflow-hidden flex items-center justify-center <?= $isPrepayLine ? 'bg-teal-100' : ($isPostpaidLine ? 'bg-amber-100' : 'bg-gray-100') ?>">
                                                        <?php if ($isPrepayLine): ?>
                                                            <i data-lucide="wallet" class="w-5 h-5 text-teal-700"></i>
                                                        <?php elseif ($isPostpaidLine): ?>
                                                            <i data-lucide="receipt" class="w-5 h-5 text-amber-700"></i>
                                                        <?php else: ?>
                                                        <img src="../products/<?= htmlspecialchars($item['product_image'] ?? '') ?>" 
                                                             alt="<?= htmlspecialchars($item['product_name']) ?>" 
                                                             class="w-full h-full object-cover" 
                                                             onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';if(typeof lucide !== 'undefined'){lucide.createIcons();}">
                                                        <div class="w-full h-full flex items-center justify-center bg-gray-100" style="display:none;">
                                                            <i data-lucide="package" class="w-5 h-5 text-gray-400"></i>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex flex-col">
                                                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($item['product_name']) ?></span>
                                                        <?php if ($isPrepayLine): ?>
                                                        <span class="text-xs text-teal-700 font-medium">Prepayment · not inventory</span>
                                                        <?php elseif ($isPostpaidLine): ?>
                                                        <span class="text-xs text-amber-800 font-medium">Postpaid charge · not inventory</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200 text-end" data-label="Qty"><?= $item['quantity'] ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200 text-end" data-label="Unit Price"><?php if ($isPrepayLine): ?>N$<?= number_format(abs((float)$item['price']), 2) ?> <span class="text-teal-700 text-xs">(credit)</span><?php elseif ($isPostpaidLine): ?>N$<?= number_format((float)$item['price'], 2) ?> <span class="text-amber-800 text-xs">(charge)</span><?php else: ?>N$<?= number_format($item['price'], 2) ?><?php endif; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-end <?= $isPrepayLine ? 'text-teal-800' : ($isPostpaidLine ? 'text-amber-900' : 'text-gray-800 dark:text-gray-200') ?>" data-label="Total"><?php if ($isPrepayLine): ?>−N$<?= number_format(abs($itemTotal), 2) ?><?php else: ?>N$<?= number_format($itemTotal, 2) ?><?php endif; ?></td>
                                            <?php if ($viewTab['status'] === 'open'): ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium" data-label="Actions">
                                                <div class="flex items-center justify-center gap-2">
                                                    <?php if (!$isPrepayLine): ?>
                                                    <button onclick="openEditItemModal(<?= $item['id'] ?>, '<?= htmlspecialchars($item['product_name'], ENT_QUOTES) ?>', <?= $item['quantity'] ?>, <?= $item['price'] ?>, <?= $viewTab['id'] ?>)" 
                                                            class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800 disabled:opacity-50 disabled:pointer-events-none dark:text-blue-500 dark:hover:text-blue-400" 
                                                            title="Edit">
                                                        <i data-lucide="pencil" class="w-4 h-4"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button type="button"
                                                            onclick="openDeleteTabItemModal(this)"
                                                            data-delete-item-id="<?= (int)$item['id'] ?>"
                                                            data-tab-id="<?= (int)$viewTab['id'] ?>"
                                                            data-line-kind="<?= $isPrepayLine ? 'prepay' : ($isPostpaidLine ? 'postpaid' : 'product') ?>"
                                                            data-product-name="<?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800 disabled:opacity-50 disabled:pointer-events-none dark:text-red-500 dark:hover:text-red-400"
                                                            title="Remove">
                                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($tabGratuityEnabled && $tabGratuityAmount > 0.001): ?>
                                    <tr class="bg-teal-50/40">
                                        <td colspan="<?= $viewTab['status'] === 'open' ? '3' : '3' ?>" class="px-6 py-3 text-end text-sm text-gray-700" data-label="">Gratuity (<?= htmlspecialchars(rtrim(rtrim(number_format($tabGratuityPercent, 2, '.', ''), '0'), '.')) ?>%):</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-teal-800 text-end" data-label="">N$<?= number_format($tabGratuityAmount, 2) ?></td>
                                        <?php if ($viewTab['status'] === 'open'): ?>
                                        <td class="px-6 py-4" data-label=""></td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endif; ?>
                                    <tr class="border-t-2 border-gray-300 bg-gray-50 font-semibold">
                                        <td colspan="<?= $viewTab['status'] === 'open' ? '3' : '3' ?>" class="px-6 py-4 text-end text-sm text-gray-700 dark:text-gray-300" data-label="">Total<?= ($tabGratuityEnabled && $tabGratuityAmount > 0.001) ? ' (incl. gratuity)' : '' ?>:</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-200 text-end" data-label="">N$<?= number_format($itemsTotal + ($tabGratuityEnabled ? $tabGratuityAmount : 0), 2) ?></td>
                                        <?php if ($viewTab['status'] === 'open'): ?>
                                        <td class="px-6 py-4" data-label=""></td>
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

               
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full hidden" style="z-index: 10001;">
        <div class="relative top-20 mx-auto mb-10 max-w-md" style="z-index: 10002;">
            <div class="bg-white rounded-lg shadow-lg border border-gray-200">
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-900">Make Payment</h3>
                    <button onclick="closePaymentModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>

                <form method="POST" id="paymentForm">
                    <input type="hidden" name="tab_id" value="<?= $viewTab['id'] ?>">
                    
                    <div class="p-6 space-y-4">
                       
                        <!-- Amount + Tip (same row) -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="min-w-0">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount (N$)</label>
                                <input type="number" name="payment_amount" id="paymentAmount" step="0.01" min="0.01" 
                                       value="<?= number_format($defaultPaymentModalAmount, 2, '.', '') ?>" required readonly
                                       class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-100 text-gray-800 cursor-not-allowed [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none focus:ring-2 focus:ring-teal-500/30 focus:border-gray-200">
                            </div>

                            <div class="min-w-0">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tip (N$)</label>
                                <div class="kioskboard-input-wrap">
                                    <input type="number" name="tip_amount" id="tipAmount" step="0.01" min="0"
                                           value="0" autocomplete="off"
                                           data-kioskboard-type="keyboard" data-kioskboard-placement="side" data-kioskboard-specialcharacters="false"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 js-kioskboard-input js-kioskboard-decimal">
                                    <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
                            <div class="grid grid-cols-3 gap-2">
                                <input type="radio" name="payment_method" id="method_cash" value="cash" class="hidden" checked>
                                <label for="method_cash" class="flex flex-col items-center justify-center gap-1.5 p-3 border-2 border-gray-300 rounded-lg cursor-pointer hover:border-teal-500 hover:bg-teal-50">
                                    <i data-lucide="banknote" class="w-5 h-5 text-gray-600"></i>
                                    <span class="text-sm font-medium">Cash</span>
                                </label>
                                
                                <input type="radio" name="payment_method" id="method_eft" value="eft" class="hidden">
                                <label for="method_eft" class="flex flex-col items-center justify-center gap-1.5 p-3 border-2 border-gray-300 rounded-lg cursor-pointer hover:border-teal-500 hover:bg-teal-50">
                                    <i data-lucide="credit-card" class="w-5 h-5 text-gray-600"></i>
                                    <span class="text-sm font-medium">EFT</span>
                                </label>
                                
                                <input type="radio" name="payment_method" id="method_mixed" value="mixed" class="hidden">
                                <label for="method_mixed" class="flex flex-col items-center justify-center gap-1.5 p-3 border-2 border-gray-300 rounded-lg cursor-pointer hover:border-teal-500 hover:bg-teal-50">
                                    <i data-lucide="wallet" class="w-5 h-5 text-gray-600"></i>
                                    <span class="text-sm font-medium">Mixed</span>
                                </label>
                            </div>
                        </div>

                        <!-- Cash tendered (cash method only — for change calculation) -->
                        <div id="cashTenderedWrap" class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1" for="cashTendered">Cash tendered (N$)</label>
                            <div class="kioskboard-input-wrap">
                                <input type="number" name="cash_tendered" id="cashTendered" step="0.01" min="0"
                                       autocomplete="off"
                                       data-kioskboard-type="keyboard" data-kioskboard-placement="side" data-kioskboard-specialcharacters="false"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 js-kioskboard-input js-kioskboard-decimal"
                                       placeholder="Amount customer hands over">
                                <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                            </div>
                            <div id="tabCashButtonsContainer" class="flex flex-wrap gap-2 mt-2">
                                <button type="button" class="bg-sky-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-sky-800 transition-colors duration-300 text-sm" onclick="addTabCashTendered(5)">N$5</button>
                                <button type="button" class="bg-teal-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-teal-700 transition-colors duration-300 text-sm" onclick="addTabCashTendered(10)">N$10</button>
                                <button type="button" class="bg-red-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-red-700 transition-colors duration-300 text-sm" onclick="addTabCashTendered(20)">N$20</button>
                                <button type="button" class="bg-yellow-500 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-yellow-600 transition-colors duration-300 text-sm" onclick="addTabCashTendered(30)">N$30</button>
                                <button type="button" class="bg-orange-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-orange-700 transition-colors duration-300 text-sm" onclick="addTabCashTendered(50)">N$50</button>
                                <button type="button" class="bg-neutral-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-neutral-800 transition-colors duration-300 text-sm" onclick="addTabCashTendered(100)">N$100</button>
                                <button type="button" class="bg-lime-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-lime-800 transition-colors duration-300 text-sm" onclick="addTabCashTendered(200)">N$200</button>
                            </div>
                            <p class="text-xs text-gray-500">Defaults to payment amount if left empty. Change is shown after payment.</p>
                            <p id="cashChangePreview" class="text-sm font-medium text-teal-700 hidden"></p>
                        </div>

              
                        <!-- Mixed Payment Fields (cash + EFT — same row on sm+) -->
                        <div id="mixedFields" class="hidden space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="min-w-0">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Cash tendered (N$)</label>
                                    <div class="kioskboard-input-wrap">
                                        <input type="number" name="cash_amount" id="cashAmount" step="0.01" min="0"
                                               autocomplete="off"
                                               data-kioskboard-type="keyboard" data-kioskboard-placement="side" data-kioskboard-specialcharacters="false"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 js-kioskboard-input js-kioskboard-decimal">
                                        <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">EFT (N$)</label>
                                    <div class="kioskboard-input-wrap">
                                        <input type="number" name="eft_amount" id="eftAmount" step="0.01" min="0"
                                               autocomplete="off"
                                               data-kioskboard-type="keyboard" data-kioskboard-placement="side" data-kioskboard-specialcharacters="false"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 js-kioskboard-input js-kioskboard-decimal">
                                        <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs text-gray-600">Payment due: <span id="mixedDue" class="font-semibold">N$0.00</span> · Tendered: <span id="mixedTotal" class="font-semibold">N$0.00</span></p>
                            <p id="mixedChangePreview" class="text-sm font-medium text-teal-700 hidden"></p>
                        </div>

                        <!-- Cash back (EFT / mixed only — extra card amount, cash from till) -->
                        <div id="tabCashBackWrap" class="hidden space-y-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1" for="tabCashBackAmount">Cash back (N$)</label>
                            <div class="kioskboard-input-wrap">
                                <input type="number" name="cash_back_amount" id="tabCashBackAmount" step="0.01" min="0" value="0"
                                       autocomplete="off"
                                       data-kioskboard-type="keyboard" data-kioskboard-placement="side" data-kioskboard-specialcharacters="false"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 js-kioskboard-input js-kioskboard-decimal">
                                <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                            </div>
                            <p class="text-xs text-gray-500">Optional. Customer receives this cash; records till cash-out and matching EFT (same ref/wallet as above).</p>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-3 pt-4 border-t border-gray-200">
                            <button type="submit" class="flex-1 bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded-lg transition-colors">
                                Process Payment
                            </button>
                            <button type="button" onclick="closePaymentModal()" 
                                    class="px-4 py-2 border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium rounded-lg transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?= tab_prepay_postpaid_modal_html($viewTab) ?>

    <!-- Standalone tip (process_tips.php — tips table + cash-up) -->
    <div id="tabStandaloneTipModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full hidden" style="z-index: 10001;">
        <div class="relative top-12 mx-auto mb-10 max-w-md" style="z-index: 10002;">
            <div class="bg-white rounded-lg shadow-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-900">Add tip</h3>
                    <button type="button" onclick="closeTabStandaloneTipModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form id="tabStandaloneTipForm" onsubmit="submitTabStandaloneTip(event)" class="p-6 space-y-4">
                    <input type="hidden" id="tabTipContextTabId" value="">
                    <input type="hidden" id="tabTipContextTabName" value="">
                    <p class="text-sm text-gray-500">Records a tip in the same way as the cashier &quot;Add tips&quot; tool (separate from the Pay modal tip on receipts).</p>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="tabTipDate">Date</label>
                        <input type="date" id="tabTipDate" name="date" value="<?= date('Y-m-d') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="tabTipType">Tip type</label>
                        <select id="tabTipType" name="tip_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400" onchange="toggleTabStandaloneTipTypeFields()">
                            <option value="cash_card">Cash / card</option>
                            <option value="inventory">Inventory (e.g. drink)</option>
                        </select>
                    </div>
                    <div id="tabTipCashCardFields" class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1" for="tabStandaloneTipAmount">Amount (N$)</label>
                            <input type="number" step="0.01" id="tabStandaloneTipAmount" name="amount" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400" placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1" for="tabTipPaymentMethod">Payment method</label>
                            <select id="tabTipPaymentMethod" name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                    </div>
                    <div id="tabTipInventoryFields" class="space-y-3 hidden">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1" for="tabTipProductId">Product</label>
                            <select id="tabTipProductId" name="product_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400">
                                <option value="">Select product</option>
                                <?php foreach ($productsForTabTips as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (N$<?= number_format((float)$p['price'], 2) ?>, stock: <?= (int)$p['quantity'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1" for="tabTipQuantity">Quantity</label>
                            <input type="number" min="1" id="tabTipQuantity" name="quantity" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400" value="1">
                            <p class="text-xs text-gray-500 mt-1">Deducts from stock.</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="tabTipNotes">Notes (optional)</label>
                        <textarea id="tabTipNotes" name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500/40 focus:border-amber-400" placeholder="Appended to tab context."></textarea>
                    </div>
                    <div class="flex gap-3 pt-2 border-t border-gray-200">
                        <button type="button" onclick="closeTabStandaloneTipModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition-colors">Record tip</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div id="editItemModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden" style="z-index: 10001;">
        <div class="relative top-20 mx-auto p-6 border w-96 shadow-xl rounded-lg bg-white" style="z-index: 10002;">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-lg font-semibold text-gray-900">Edit Product</h3>
                <button onclick="closeEditItemModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <form method="POST" id="editItemForm">
                <input type="hidden" name="edit_item_id" id="edit_item_id">
                <input type="hidden" name="tab_id" id="edit_tab_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                        <input type="text" id="edit_product_name" readonly
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-600">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input type="number" name="edit_item_quantity" id="edit_item_quantity" step="1" min="1" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit Price (N$)</label>
                        <input type="number" id="edit_item_price" step="0.01" min="0" readonly
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-600">
                        <input type="hidden" name="edit_item_price" id="edit_item_price_hidden">
                    </div>
                    <div class="pt-2">
                        <p class="text-sm text-gray-600">Total: <span id="edit_item_total" class="font-semibold text-gray-900">N$0.00</span></p>
                    </div>
                    <div class="flex gap-2 pt-4">
                        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>Save Changes
                        </button>
                        <button type="button" onclick="closeEditItemModal()" 
                                class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 px-4 rounded-lg transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <form id="deleteTabItemForm" method="POST" class="hidden" aria-hidden="true">
        <input type="hidden" name="delete_item_id" value="">
        <input type="hidden" name="tab_id" value="">
    </form>

    <!-- Edit Tab Name Modal -->
    <div id="editTabNameModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden" style="z-index: 10001;">
        <div class="relative top-20 mx-auto p-6 border w-96 shadow-xl rounded-lg bg-white" style="z-index: 10002;">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-lg font-semibold text-gray-900">Edit Tab Name</h3>
                <button onclick="closeEditTabNameModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <form method="POST" id="editTabNameForm">
                <input type="hidden" name="tab_id" id="edit_tab_name_tab_id">
                <input type="hidden" name="edit_tab_name" value="1">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tab Name</label>
                        <div class="kioskboard-input-wrap">
                            <input type="text" name="new_tab_name" id="edit_tab_name_input" required autocomplete="off"
                                   class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 js-kioskboard-input js-kioskboard-text">
                            <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                        </div>
                    </div>
                    <div class="flex gap-2 pt-4">
                        <button type="submit" class="flex-1 inline-flex items-center justify-center bg-teal-600 hover:bg-teal-700 text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>Save Changes
                        </button>
                        <button type="button" onclick="closeEditTabNameModal()" 
                                class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 px-4 rounded-lg transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

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

        var TAB_TIPS_API = <?= json_encode('../process_tips.php') ?>;

        // sendToPrinter function is now loaded from ../receipt.php?js=true
        // The function is defined in receipt.php and automatically handles Android printing
        // The Android interceptor in MainActivity.java only listens to receipt.php calls
        // This includes support for:
        // - Tab balance receipts (is_tab_balance_receipt: true)
        // - Payment receipts (is_payment_receipt: true)
        // - Regular tab prints (tab_id/table_id)
        // - All other receipt types
        if (typeof sendToPrinter === 'undefined') {
            console.warn('[admin/view-tab.php] sendToPrinter not loaded from receipt.php, using fallback');
            function sendToPrinter(receiptData) {
                // Ensure print_only flag is set for regular receipts
                if (!receiptData.print_only && !receiptData.is_cashup_report && !receiptData.is_balance_receipt && !receiptData.is_tab_balance_receipt && !receiptData.is_tab_copy_receipt && !receiptData.is_payment_receipt) {
                    receiptData.print_only = true;
                }
                
                // Add business info to receipt data
                var dataWithBusiness = Object.assign({}, receiptData, {
                    business_name: receiptData.business_name || businessInfo.business_name,
                    location: receiptData.location || businessInfo.location,
                    phone: receiptData.phone || businessInfo.phone,
                    footer_text: receiptData.footer_text || businessInfo.footer_text,
                    vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                    vat_rate: receiptData.vat_rate || businessInfo.vat_rate
                });
                
                // Use fetch to receipt.php - the interceptor will catch this
                return fetch('../receipt.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(dataWithBusiness)
                }).then(function(r) { 
                    return r.json();
                });
            }
        }

        // Edit Tab Name Modal functions
        function openEditTabNameModal(tabId, currentTabName) {
            document.getElementById('edit_tab_name_tab_id').value = tabId;
            document.getElementById('edit_tab_name_input').value = currentTabName;
            document.getElementById('editTabNameModal').classList.remove('hidden');
            lucide.createIcons();
            if (window.PosKioskBoard) {
                window.PosKioskBoard.bindText('#edit_tab_name_input');
            }
            setTimeout(function () {
                var input = document.getElementById('edit_tab_name_input');
                if (!input) {
                    return;
                }
                input.focus();
                input.select();
                if (window.PosKioskBoard && window.PosKioskBoard.openInput) {
                    window.PosKioskBoard.openInput(input);
                }
            }, 100);
        }

        function closeEditTabNameModal() {
            if (window.PosKioskBoard && window.PosKioskBoard.close) {
                window.PosKioskBoard.close();
            }
            document.getElementById('editTabNameModal').classList.add('hidden');
            document.getElementById('editTabNameForm').reset();
        }

        function openDeleteTabItemModal(btn) {
            const itemId = btn.getAttribute('data-delete-item-id');
            const tabId = btn.getAttribute('data-tab-id');
            const lineKind = btn.getAttribute('data-line-kind') || 'product';
            const productName = btn.getAttribute('data-product-name') || '';

            const kindMeta = {
                prepay: {
                    title: 'Remove prepayment credit?',
                    hint: 'This removes the credit line from the tab. Inventory is not changed.'
                },
                postpaid: {
                    title: 'Remove postpaid charge?',
                    hint: 'This removes the charge line from the tab. Inventory is not changed.'
                },
                product: {
                    title: 'Remove product from tab?',
                    hint: 'This line will be removed. If it came from inventory, stock will be restored.'
                }
            };
            const meta = kindMeta[lineKind] || kindMeta.product;

            Swal.fire({
                title: meta.title,
                icon: 'warning',
                iconColor: '#d97706',
                showCancelButton: true,
                focusCancel: true,
                confirmButtonText: 'Remove',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                reverseButtons: true,
                customClass: {
                    popup: 'rounded-2xl shadow-2xl border border-gray-200/90 px-5 py-4 max-w-md !bg-white',
                    title: 'text-xl font-semibold text-gray-900 tracking-tight pb-0',
                    htmlContainer: 'text-left !mt-3',
                    actions: 'flex flex-row-reverse flex-wrap gap-2 justify-end w-full mt-6 !mb-0 pt-2 border-t border-gray-100',
                    confirmButton: 'inline-flex items-center justify-center rounded-xl bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-5 py-2.5 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1',
                    cancelButton: 'inline-flex items-center justify-center rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold px-5 py-2.5 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-1'
                },
                html: '<p class="text-gray-600 text-sm leading-relaxed">' + meta.hint + '</p>' +
                    '<p class="text-xs font-medium text-gray-500 uppercase tracking-wide mt-4 mb-1">Line</p>' +
                    '<p id="swal-delete-tab-item-name" class="text-sm font-semibold text-gray-900 px-3 py-2.5 rounded-xl bg-gray-50 border border-gray-100/80"></p>',
                didOpen: () => {
                    const el = document.getElementById('swal-delete-tab-item-name');
                    if (el) {
                        el.textContent = productName;
                    }
                }
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }
                const form = document.getElementById('deleteTabItemForm');
                if (!form) {
                    return;
                }
                const delInput = form.querySelector('[name="delete_item_id"]');
                const tabInput = form.querySelector('[name="tab_id"]');
                if (delInput) {
                    delInput.value = itemId;
                }
                if (tabInput) {
                    tabInput.value = tabId;
                }
                form.submit();
            });
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
            lucide.createIcons();
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
            const cashTenderedInput = document.getElementById('cashTendered');
            
            function getFixedPaymentAmount() {
                const fixed = paymentAmount?.dataset?.fixedAmount ?? paymentAmount?.value ?? '0';
                return parseFloat(fixed) || 0;
            }
            
            // Listen to radio button changes
            paymentMethodRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    updatePaymentMethodUI(this.value);
                    updateCashChangePreview();
                    updateMixedPaymentPreview();
                });
            });
            
            if (cashAmount && eftAmount) {
                cashAmount.addEventListener('input', updateMixedPaymentPreview);
                eftAmount.addEventListener('input', updateMixedPaymentPreview);
            }
            
            if (paymentAmount && cashTenderedInput) {
                paymentAmount.addEventListener('input', function() {
                    const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
                    if (selectedMethod && selectedMethod.value === 'cash') {
                        const pay = parseFloat(paymentAmount.value) || 0;
                        if (pay > 0) {
                            cashTenderedInput.value = pay.toFixed(2);
                        }
                    }
                    updateCashChangePreview();
                });
                cashTenderedInput.addEventListener('input', updateCashChangePreview);
            }
            
            // Form validation
            const paymentForm = document.getElementById('paymentForm');
            if (paymentForm) {
                paymentForm.addEventListener('submit', function(e) {
                    const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
                    
                    if (selectedMethod && selectedMethod.value === 'cash') {
                        const pay = parseFloat(document.getElementById('paymentAmount')?.value || 0);
                        let tendered = parseFloat(document.getElementById('cashTendered')?.value || 0);
                        if (isNaN(tendered) || tendered <= 0) {
                            tendered = pay;
                        }
                        if (tendered + 0.001 < pay) {
                            e.preventDefault();
                            Swal.fire({
                                icon: 'error',
                                title: 'Invalid cash amount',
                                text: 'Cash tendered must be at least the payment amount.',
                                confirmButtonColor: '#0d9488',
                            });
                            return false;
                        }
                    }
                    
                    if (selectedMethod && selectedMethod.value === 'mixed') {
                        const cash = parseFloat(cashAmount?.value || 0);
                        const eft = parseFloat(eftAmount?.value || 0);
                        const total = parseFloat(paymentAmount?.value || 0);
                        
                        if (cash + eft + 0.001 < total) {
                            e.preventDefault();
                            Swal.fire({
                                icon: 'error',
                                title: 'Invalid Amount',
                                text: 'Cash + EFT must be at least the payment amount',
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
            if (window.PosKioskBoard && typeof window.PosKioskBoard.close === 'function') {
                window.PosKioskBoard.close();
            }
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

            if (urlParams.get('openpay') === '1') {
                const prepayDefault = <?= json_encode(max(0.01, $defaultPaymentModalAmount)) ?>;
                openPaymentModal(<?= $viewTab['id'] ?>, prepayDefault);
                window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $tabId ?>');
            }

            // Handle print_balance parameter from URL
            if (urlParams.get('print_balance') === '1') {
                openTabPrintChoice(<?= $viewTab['id'] ?>, <?= json_encode($viewTab['tab_name'] ?? '', JSON_UNESCAPED_UNICODE) ?>, <?= number_format($viewTab['current_balance'], 2, '.', '') ?>);
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $tabId ?>');
            }

            // Handle payment_success parameter - print receipt automatically
            if (urlParams.get('payment_success') === '1' && urlParams.get('order_id')) {
                const orderData = <?= $orderDataForReceipt ? json_encode($orderDataForReceipt) : 'null' ?>;
                if (orderData) {
                    printPaymentReceipt(orderData).finally(function() {
                        window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $tabId ?>');
                    });
                }
            }
        });

        // Print payment receipt
        function printPaymentReceipt(orderData) {
            // Format receipt data for receipt.php
            const receiptData = {
                print_only: true,
                is_payment_receipt: true,
                gratuity_amount: Number(orderData.gratuity_amount) || 0,
                gratuity: Math.max(0, Number(orderData.gratuity) || ((Number(orderData.gratuity_amount) || 0) - (Number(orderData.tips) || 0))),
                gratuity_percent_applied: orderData.gratuity_percent_applied != null ? orderData.gratuity_percent_applied : null,
                gratuity_included_in_total: orderData.gratuity_included_in_total != null ? orderData.gratuity_included_in_total : 1,
                order_id: orderData.order_id,
                items: orderData.items,
                cashier_username: orderData.cashier_username,
                total: orderData.total,
                tips: orderData.tips || 0,
                cash_received: orderData.cash_received || 0,
                payment_method: orderData.payment_method || 'cash',
                transaction_ref: orderData.transaction_ref || '',
                wallet_provider: orderData.wallet_provider || '',
                tab_id: orderData.tab_id,
                tab_name: orderData.tab_name,
                creditor_name: orderData.creditor_name || '',
                vat_inclusive: orderData.vat_inclusive || 'exclusive',
                vat_rate: orderData.vat_rate || 15.0,
                payment_date: orderData.payment_date || '',
                closed_at: orderData.closed_at || '',
            };

            // Add mixed payment details if applicable
            if (orderData.payment_method === 'mixed') {
                receiptData.cash_amount = orderData.cash_amount || 0;
                receiptData.eft_amount = orderData.eft_amount || 0;
                receiptData.eft_transaction_ref = orderData.eft_transaction_ref || '';
                receiptData.eft_wallet_provider = orderData.eft_wallet_provider || '';
            }

            return sendToPrinter(receiptData)
            .then(printData => {
                if (printData.success) {
                    const total = parseFloat(orderData.total) || 0;
                    const method = orderData.payment_method || '';
                    let change = 0;
                    if (method === 'cash') {
                        const tendered = parseFloat(orderData.cash_received) || 0;
                        change = Math.max(0, tendered - total);
                    } else if (method === 'mixed') {
                        const cashTendered = parseFloat(orderData.cash_received) || parseFloat(orderData.cash_amount) || 0;
                        const eft = parseFloat(orderData.eft_amount) || 0;
                        change = Math.max(0, cashTendered + eft - total);
                    }
                    if (method === 'cash' || method === 'mixed') {
                        const changeBlock = change < 0.005
                            ? '<p class="text-lg text-gray-700 mt-4">Exact amount — <span class="font-semibold">no change</span></p>'
                            : `<p class="text-sm text-gray-600 mt-3 mb-1">Change to give the customer</p>
                               <p class="text-4xl font-bold text-teal-600 tracking-tight">N$ ${change.toFixed(2)}</p>`;
                        Swal.fire({
                            icon: 'success',
                            title: 'Payment successful',
                            html: `<p class="text-sm text-gray-600">Receipt sent to printer.</p>${changeBlock}`,
                            confirmButtonColor: '#0d9488',
                        });
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: 'Receipt Printed',
                            text: 'Payment receipt has been sent to printer.',
                            confirmButtonColor: '#3B82F6',
                            timer: 3000,
                            showConfirmButton: false
                        });
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Printing Failed',
                        text: printData.message || 'Failed to print receipt.',
                        confirmButtonColor: '#3B82F6',
                    });
                }
                return printData;
            })
            .catch(error => {
                console.error('Print error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Printing Failed',
                    text: error.message || 'An error occurred while printing the receipt.',
                    confirmButtonColor: '#3B82F6',
                });
                throw error;
            });
        }

        function openTabPrintChoice(tabId, tabName, balance) {
            Swal.fire({
                title: 'Print',
                text: 'Choose receipt type',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: 'Copy receipt',
                denyButtonText: 'Balance receipt',
                cancelButtonText: 'Cancel',
                focusCancel: true,
                reverseButtons: true,
                buttonsStyling: false,
                customClass: {
                    popup: 'rounded-2xl shadow-2xl border border-gray-200/90 px-5 py-4 max-w-md !bg-white',
                    title: 'text-xl font-semibold text-gray-900 tracking-tight',
                    actions: 'flex flex-row flex-wrap gap-2 justify-end w-full mt-4 !mb-0 pt-2 border-t border-gray-100',
                    confirmButton: 'inline-flex items-center justify-center rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold px-4 py-2.5',
                    denyButton: 'inline-flex items-center justify-center rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold px-4 py-2.5',
                    cancelButton: 'inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold px-4 py-2.5'
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    printTabCopyReceipt(tabId, tabName, balance);
                } else if (result.isDenied) {
                    printTabBalance(tabId, tabName, balance);
                }
            });
        }

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
                    'tip_amount' => floatval($payment['tip_amount'] ?? 0),
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
                gratuity: <?= json_encode(round($tabGratuityEnabled ? $tabGratuityAmount : 0.0, 2)) ?>,
                gratuity_percent_applied: <?= json_encode($tabGratuityEnabled ? $tabGratuityPercent : null) ?>,
                gratuity_included_in_total: <?= json_encode($tabGratuityEnabled && $tabGratuityAmount > 0.001 ? 1 : 0) ?>,
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
                    text: error.message || 'An error occurred while printing the balance receipt.',
                    confirmButtonColor: '#3B82F6',
                });
            });
        }

        function printTabCopyReceipt(tabId, tabName, balance) {
            const items = <?= json_encode(array_map(function($item) {
                return [
                    'name' => $item['product_name'],
                    'quantity' => intval($item['quantity']),
                    'price' => floatval($item['price']) * intval($item['quantity']),
                    'unit_price' => floatval($item['price'])
                ];
            }, $tabItems)) ?>;
            const payments = <?= json_encode(array_map(function($payment) {
                return [
                    'amount' => floatval($payment['amount']),
                    'tip_amount' => floatval($payment['tip_amount'] ?? 0),
                    'payment_method' => $payment['payment_method'],
                    'payment_date' => $payment['payment_date']
                ];
            }, $tabPayments)) ?>;
            var gratuity = <?= json_encode(round($tabGratuityEnabled ? ($tabGratuityRemaining > 0.001 ? $tabGratuityRemaining : $tabGratuityAmount) : 0.0, 2)) ?>;
            var gratuityPercent = <?= json_encode($tabGratuityEnabled ? $tabGratuityPercent : null) ?>;
            const receiptData = {
                print_only: true,
                is_tab_copy_receipt: true,
                gratuity: gratuity,
                gratuity_percent_applied: gratuityPercent,
                gratuity_included_in_total: gratuity > 0 ? 1 : 0,
                tab_id: tabId,
                tab_name: tabName,
                creditor_name: '<?= htmlspecialchars($viewTab['creditor_name'] ?? 'N/A', ENT_QUOTES) ?>',
                total_balance: balance,
                items: items,
                payments: payments,
                cashier_username: '<?= htmlspecialchars($viewTab['opened_by_username'] ?? 'Unknown', ENT_QUOTES) ?>',
                order_started: <?= json_encode(!empty($viewTab['created_at']) ? date('g:i A', strtotime($viewTab['created_at'])) : '') ?>,
                receipt_number: String(tabId),
                receipt_copy_number: 1
            };
            sendToPrinter(receiptData)
            .then(function (printData) {
                if (printData.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Copy receipt printed',
                        text: 'Guest check for ' + tabName + ' sent to printer.',
                        confirmButtonColor: '#3B82F6',
                        timer: 4000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Printing failed',
                        text: printData.message || 'Failed to print copy receipt.',
                        confirmButtonColor: '#3B82F6'
                    });
                }
            })
            .catch(function (error) {
                console.error('Print error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Printing failed',
                    text: error.message || 'An error occurred while printing.',
                    confirmButtonColor: '#3B82F6'
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

        /** Same quick-add behaviour as home.php cart cash buttons */
        function addTabCashTendered(amount) {
            const el = document.getElementById('cashTendered');
            if (!el) return;
            const cur = parseFloat(el.value);
            const base = isNaN(cur) || cur <= 0 ? 0 : cur;
            el.value = (base + amount).toFixed(2);
            updateCashChangePreview();
        }

        function updateCashChangePreview() {
            const preview = document.getElementById('cashChangePreview');
            const paymentAmountEl = document.getElementById('paymentAmount');
            const cashTenderedEl = document.getElementById('cashTendered');
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            if (!preview || !paymentAmountEl || !cashTenderedEl) return;
            if (!selectedMethod || selectedMethod.value !== 'cash') {
                preview.classList.add('hidden');
                return;
            }
            const pay = parseFloat(paymentAmountEl.value) || 0;
            let tendered = parseFloat(cashTenderedEl.value);
            if (isNaN(tendered) || tendered <= 0) {
                tendered = pay;
            }
            if (pay <= 0) {
                preview.classList.add('hidden');
                return;
            }
            if (tendered + 0.001 < pay) {
                preview.textContent = 'Cash tendered must be at least N$' + pay.toFixed(2);
                preview.classList.remove('hidden', 'text-teal-700');
                preview.classList.add('text-red-600');
                return;
            }
            const change = Math.max(0, tendered - pay);
            preview.textContent = change < 0.005 ? 'Exact amount — no change' : ('Change: N$ ' + change.toFixed(2));
            preview.classList.remove('hidden', 'text-red-600');
            preview.classList.add('text-teal-700');
        }

        function updateMixedPaymentPreview() {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            const paymentAmountEl = document.getElementById('paymentAmount');
            const cashAmountEl = document.getElementById('cashAmount');
            const eftAmountEl = document.getElementById('eftAmount');
            const mixedTotalEl = document.getElementById('mixedTotal');
            const mixedDueEl = document.getElementById('mixedDue');
            const preview = document.getElementById('mixedChangePreview');
            if (!selectedMethod || selectedMethod.value !== 'mixed') {
                if (preview) preview.classList.add('hidden');
                return;
            }
            const fixedTotal = parseFloat(paymentAmountEl?.dataset?.fixedAmount ?? paymentAmountEl?.value ?? '0') || 0;
            const cash = parseFloat(cashAmountEl?.value || 0) || 0;
            const eft = parseFloat(eftAmountEl?.value || 0) || 0;
            const tendered = cash + eft;
            if (mixedDueEl) mixedDueEl.textContent = 'N$' + fixedTotal.toFixed(2);
            if (mixedTotalEl) mixedTotalEl.textContent = 'N$' + tendered.toFixed(2);
            if (!preview) return;
            if (fixedTotal <= 0 || ((cashAmountEl?.value ?? '') === '' && (eftAmountEl?.value ?? '') === '')) {
                preview.classList.add('hidden');
                return;
            }
            if (tendered + 0.001 < fixedTotal) {
                preview.textContent = 'Cash + EFT must be at least N$' + fixedTotal.toFixed(2);
                preview.classList.remove('hidden', 'text-teal-700');
                preview.classList.add('text-red-600');
                return;
            }
            const change = Math.max(0, tendered - fixedTotal);
            preview.textContent = change < 0.005 ? 'Exact amount — no change' : ('Change: N$ ' + change.toFixed(2));
            preview.classList.remove('hidden', 'text-red-600');
            preview.classList.add('text-teal-700');
        }

        // Open Payment Modal
        function openPaymentModal(tabId, amount) {
            if (window.PosKioskBoard && typeof window.PosKioskBoard.close === 'function') {
                window.PosKioskBoard.close();
            }
            if (window.PosKioskBoard && typeof window.PosKioskBoard.init === 'function') {
                window.PosKioskBoard.init('#paymentModal');
            }
            const modal = document.getElementById('paymentModal');
            const amountInput = document.getElementById('paymentAmount');
            
            const fixedAmount = parseFloat(amount).toFixed(2);

            // Set amount
            if (amountInput) {
                amountInput.value = fixedAmount;
                amountInput.dataset.fixedAmount = fixedAmount;
            }
            
            // Reset form and set defaults
            const form = document.getElementById('paymentForm');
            if (form) {
                form.reset();
                // Set amount again after reset
                if (amountInput) {
                    amountInput.value = fixedAmount;
                    amountInput.dataset.fixedAmount = fixedAmount;
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
            const cashTenderedEl = document.getElementById('cashTendered');
            if (cashTenderedEl && amountInput) {
                cashTenderedEl.value = parseFloat(amount).toFixed(2);
            }
            updateCashChangePreview();
            updateMixedPaymentPreview();
            
            // Show modal
            modal.classList.remove('hidden');
            lucide.createIcons();
        }
        
        // Update payment method UI
        function updatePaymentMethodUI(method) {
            // Remove selected class from all buttons and reset icon colors
            document.querySelectorAll('label[for^="method_"]').forEach(btn => {
                btn.classList.remove('border-teal-500', 'bg-teal-50');
                btn.classList.add('border-gray-300');
                const icon = btn.querySelector('i[data-lucide]');
                if (icon) {
                    icon.classList.remove('text-teal-600');
                    icon.classList.add('text-gray-600');
                }
            });
            
            // Show/hide fields
            const eftFields = document.getElementById('eftFields');
            const mixedFields = document.getElementById('mixedFields');
            const transactionRef = document.getElementById('transactionRef');
            const walletProvider = document.getElementById('walletProvider');
            const cashAmount = document.getElementById('cashAmount');
            const eftAmount = document.getElementById('eftAmount');
            const tabCashBackWrap = document.getElementById('tabCashBackWrap');
            const tabCashBackInput = document.getElementById('tabCashBackAmount');
            
            const cashTenderedWrap = document.getElementById('cashTenderedWrap');
            
            if (method === 'cash') {
                eftFields?.classList.add('hidden');
                mixedFields?.classList.add('hidden');
                cashTenderedWrap?.classList.remove('hidden');
                tabCashBackWrap?.classList.add('hidden');
                if (tabCashBackInput) tabCashBackInput.value = '0';
                if (transactionRef) transactionRef.removeAttribute('required');
                if (cashAmount) cashAmount.removeAttribute('required');
                if (eftAmount) eftAmount.removeAttribute('required');
                
                // Highlight cash button and icon
                const cashBtn = document.querySelector('label[for="method_cash"]');
                if (cashBtn) {
                    cashBtn.classList.add('border-teal-500', 'bg-teal-50');
                    cashBtn.classList.remove('border-gray-300');
                    const icon = cashBtn.querySelector('i[data-lucide]');
                    if (icon) {
                        icon.classList.remove('text-gray-600');
                        icon.classList.add('text-teal-600');
                    }
                }
            } else if (method === 'eft') {
                cashTenderedWrap?.classList.add('hidden');
                eftFields?.classList.remove('hidden');
                mixedFields?.classList.add('hidden');
                tabCashBackWrap?.classList.remove('hidden');
                if (transactionRef) transactionRef.removeAttribute('required');
                if (cashAmount) cashAmount.removeAttribute('required');
                if (eftAmount) eftAmount.removeAttribute('required');
                
                // Highlight EFT button and icon
                const eftBtn = document.querySelector('label[for="method_eft"]');
                if (eftBtn) {
                    eftBtn.classList.add('border-teal-500', 'bg-teal-50');
                    eftBtn.classList.remove('border-gray-300');
                    const icon = eftBtn.querySelector('i[data-lucide]');
                    if (icon) {
                        icon.classList.remove('text-gray-600');
                        icon.classList.add('text-teal-600');
                    }
                }
            } else if (method === 'mixed') {
                cashTenderedWrap?.classList.add('hidden');
                eftFields?.classList.remove('hidden');
                mixedFields?.classList.remove('hidden');
                tabCashBackWrap?.classList.remove('hidden');
                if (transactionRef) transactionRef.removeAttribute('required');
                if (cashAmount) cashAmount.setAttribute('required', 'required');
                if (eftAmount) eftAmount.setAttribute('required', 'required');
                updateMixedPaymentPreview();

                // Highlight mixed button and icon
                const mixedBtn = document.querySelector('label[for="method_mixed"]');
                if (mixedBtn) {
                    mixedBtn.classList.add('border-teal-500', 'bg-teal-50');
                    mixedBtn.classList.remove('border-gray-300');
                    const icon = mixedBtn.querySelector('i[data-lucide]');
                    if (icon) {
                        icon.classList.remove('text-gray-600');
                        icon.classList.add('text-teal-600');
                    }
                }
            }
            
            if (method === 'cash') {
                const payEl = document.getElementById('paymentAmount');
                const ctEl = document.getElementById('cashTendered');
                if (payEl && ctEl) {
                    const pay = parseFloat(payEl.value) || 0;
                    if (pay > 0) {
                        const cur = parseFloat(ctEl.value);
                        if (isNaN(cur) || cur <= 0) {
                            ctEl.value = pay.toFixed(2);
                        }
                    }
                }
            }
            updateCashChangePreview();
            updateMixedPaymentPreview();
        }

        function openTabStandaloneTipModal(tabId, tabName) {
            const form = document.getElementById('tabStandaloneTipForm');
            if (form) form.reset();
            document.getElementById('tabTipContextTabId').value = String(tabId);
            document.getElementById('tabTipContextTabName').value = tabName || '';
            const d = new Date();
            const ymd = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            const dateEl = document.getElementById('tabTipDate');
            if (dateEl) dateEl.value = ymd;
            const tipType = document.getElementById('tabTipType');
            if (tipType) tipType.value = 'cash_card';
            const qty = document.getElementById('tabTipQuantity');
            if (qty) qty.value = '1';
            toggleTabStandaloneTipTypeFields();
            const modal = document.getElementById('tabStandaloneTipModal');
            if (modal) {
                modal.classList.remove('hidden');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        }

        function closeTabStandaloneTipModal() {
            const modal = document.getElementById('tabStandaloneTipModal');
            if (modal) modal.classList.add('hidden');
        }

        function toggleTabStandaloneTipTypeFields() {
            const tipTypeEl = document.getElementById('tabTipType');
            if (!tipTypeEl) return;
            const tipType = tipTypeEl.value;
            const cashCard = document.getElementById('tabTipCashCardFields');
            const inventory = document.getElementById('tabTipInventoryFields');
            const amt = document.getElementById('tabStandaloneTipAmount');
            if (tipType === 'inventory') {
                if (cashCard) cashCard.classList.add('hidden');
                if (inventory) inventory.classList.remove('hidden');
                if (amt) amt.removeAttribute('required');
            } else {
                if (cashCard) cashCard.classList.remove('hidden');
                if (inventory) inventory.classList.add('hidden');
            }
        }

        function submitTabStandaloneTip(event) {
            event.preventDefault();
            const tabId = document.getElementById('tabTipContextTabId').value;
            const tabName = document.getElementById('tabTipContextTabName').value;
            const tipDate = (document.getElementById('tabTipDate') && document.getElementById('tabTipDate').value) || new Date().toISOString().split('T')[0];
            const tipType = document.getElementById('tabTipType').value;
            const userNotes = (document.getElementById('tabTipNotes') && document.getElementById('tabTipNotes').value || '').trim();
            const base = 'Tab: ' + (tabName || '') + ' (id ' + tabId + ')';
            const notes = userNotes ? base + ' \u2014 ' + userNotes : base;
            var data = { tip_type: tipType, notes: notes, date: tipDate };
            if (tipType === 'inventory') {
                const productId = document.getElementById('tabTipProductId').value;
                const quantity = parseInt(document.getElementById('tabTipQuantity').value, 10) || 1;
                if (!productId) {
                    Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a product.' });
                    return;
                }
                data.product_id = productId;
                data.quantity = quantity;
            } else {
                const amount = parseFloat(document.getElementById('tabStandaloneTipAmount').value);
                if (!amount || amount <= 0) {
                    Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter a valid tip amount.' });
                    return;
                }
                data.amount = amount;
                data.payment_method = (document.getElementById('tabTipPaymentMethod') && document.getElementById('tabTipPaymentMethod').value) || 'cash';
            }
            fetch(TAB_TIPS_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    Swal.fire({ icon: 'success', title: 'Success', text: result.message || 'Tip recorded', timer: 2000, showConfirmButton: true });
                    closeTabStandaloneTipModal();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'Failed to record tip' });
                }
            })
            .catch(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred.' }); });
        }
        
        // Transfer to Credit Sale functions
        function formatCreditMoney(amount) {
            return 'N$' + parseFloat(amount || 0).toFixed(2);
        }

        function getCreditorAvailableLabel(creditor) {
            if (!creditor || !creditor.is_limit_enforced) return '';
            const available = parseFloat(creditor.available_credit || 0);
            const limit = parseFloat(creditor.credit_limit || 0);
            return `${formatCreditMoney(available)} / ${formatCreditMoney(limit)}`;
        }

        function checkCreditSaleWithinLimit(creditorId, saleAmount) {
            const creditors = window._lastCreditorsList || [];
            const creditor = creditors.find(c => String(c.id) === String(creditorId));
            if (!creditor || !creditor.is_limit_enforced) return true;
            const sale = parseFloat(saleAmount || 0);
            const available = parseFloat(creditor.available_credit || 0);
            if (sale > available + 0.005) {
                Swal.fire({
                    icon: 'error',
                    title: 'Credit Limit Exceeded',
                    html: `Limit: <b>${formatCreditMoney(creditor.credit_limit)}</b><br>Outstanding: <b>${formatCreditMoney(creditor.outstanding_balance)}</b><br>Available: <b>${formatCreditMoney(available)}</b><br>Requested: <b>${formatCreditMoney(sale)}</b>`
                });
                return false;
            }
            return true;
        }

        function openTransferToCreditSaleModal(tabId, currentBalance, existingCreditorId) {
            // Fetch creditors with balances
            fetch('../get_creditors_with_balances.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        Swal.fire('Error', data.message || 'Failed to load creditors', 'error');
                        return;
                    }
                    const creditors = data.creditors || [];
                    showCreditorSelectionForTransfer(tabId, currentBalance, creditors, existingCreditorId);
                })
                .catch(error => {
                    console.error('Error fetching creditors:', error);
                    Swal.fire('Error', 'Failed to load creditors', 'error');
                });
        }
        
        function showCreditorSelectionForTransfer(tabId, currentBalance, creditors, existingCreditorId) {
            window._lastCreditorsList = creditors || [];
            // Create creditor list HTML with search
            let creditorsListHTML = '';
            if (creditors.length === 0) {
                creditorsListHTML = `
                    <div class="text-center py-8">
                        <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <p class="text-gray-600 text-xs font-medium">No active creditors</p>
                        <p class="text-gray-400 text-xs mt-0.5">Create a new account</p>
                    </div>
                `;
            } else {
                creditors.forEach(creditor => {
                    const balance = parseFloat(creditor.outstanding_balance || 0);
                    const balanceClass = balance > 0 ? 'text-orange-500 font-bold' : 'text-teal-600 font-semibold';
                    const balanceText = balance > 0 ? `N$${balance.toFixed(2)}` : 'N$0.00';
                    const available = parseFloat(creditor.available_credit || 0);
                    const limitEnforced = !!creditor.is_limit_enforced;
                    const availableClass = !limitEnforced ? balanceClass : (available <= 0.005 ? 'text-red-600 font-bold' : (available < parseFloat(creditor.credit_limit || 0) * 0.2 ? 'text-orange-500 font-bold' : 'text-teal-600 font-semibold'));
                    const rightLabel = limitEnforced ? getCreditorAvailableLabel(creditor) : balanceText;
                    const rightSub = limitEnforced ? `<div class="text-[10px] text-gray-500">Bal: ${balanceText}</div>` : '';
                    const isSelected = existingCreditorId && parseInt(creditor.id) === parseInt(existingCreditorId);
                    
                    creditorsListHTML += `
                        <div class="creditor-item bg-white rounded-lg p-2 mb-1 cursor-pointer hover:bg-gray-200 transition-colors duration-200 relative ${isSelected ? 'bg-indigo-100 border-2 border-indigo-300' : ''}" 
                             data-id="${creditor.id}" 
                             data-name="${creditor.name.toLowerCase()}"
                             data-phone="${(creditor.phone || '').toLowerCase()}"
                             data-balance="${balance}"
                             onclick="selectCreditorForTransfer(${creditor.id}, ${tabId}, ${currentBalance})">
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-2 min-w-0" style="max-width: 35%;">
                                    <svg class="w-4 h-4 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    <span class="font-medium text-gray-700 truncate">${creditor.name}</span>
                                </div>
                                ${creditor.phone ? `<span class="text-gray-500 whitespace-nowrap absolute left-1/2 transform -translate-x-1/2">${creditor.phone}</span>` : ''}
                                <div class="flex flex-col items-end ml-auto flex-shrink-0">
                                    <span class="${availableClass} font-medium whitespace-nowrap px-2 py-0.5 rounded-full text-xs bg-gray-100 border border-gray-200" style="min-width: 65px; text-align: center;">
                                        ${limitEnforced ? 'Avail: ' + rightLabel : rightLabel}
                                    </span>
                                    ${rightSub}
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Transfer to Credit Sale</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-2">
                            <p class="text-xs text-indigo-800">
                                <i data-lucide="info" class="w-4 h-4 inline-block mr-1"></i>
                                This will convert all unpaid tab items into a credit sale. The tab will be deleted and product quantities will be updated.
                            </p>
                            <p class="text-xs font-semibold text-indigo-900 mt-1">Balance: <span>N$${parseFloat(currentBalance).toFixed(2)}</span></p>
                        </div>
                        <!-- Search Bar and Create Account Button in same row -->
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <input type="text" 
                                       id="creditorSearchTransfer" 
                                       class="w-full h-10 px-3 pl-9 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                       placeholder="Search creditor...">
                                <svg class="absolute left-2.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <button id="createAccountBtnTransfer" 
                                    class="h-10 bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-3 rounded-lg transition-colors duration-200 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="bg-[#f3f4f6] rounded-lg p-2 mb-1">
                            <div class="flex items-center justify-between gap-2 text-xs text-gray-600 font-medium">
                                <div class="flex items-center gap-1.5 min-w-0" style="max-width: 35%;">
                                    <span>Name</span>
                                </div>
                                <div class="flex items-center gap-1.5 ml-auto flex-shrink-0">
                                    <span>Available</span>
                                </div>
                            </div>
                        </div>
                        <div id="creditorsListContainerTransfer" class="max-h-48 overflow-y-auto custom-scrollbar bg-[#f3f4f6] rounded-lg p-1.5" style="min-height: 100px;">
                            ${creditorsListHTML}
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Next',
                confirmButtonClass: 'swal2-confirm-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg hidden',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                didOpen: () => {
                    lucide.createIcons();
                    let selectedCreditorId = existingCreditorId;
                    
                    // Create account button
                    const createBtn = document.getElementById('createAccountBtnTransfer');
                    if (createBtn) {
                        createBtn.addEventListener('click', function() {
                            showCreateCreditorModalForTransfer(tabId, currentBalance, creditors);
                        });
                    }
                    
                    // Search functionality
                    const searchInput = document.getElementById('creditorSearchTransfer');
                    const creditorsListContainer = document.getElementById('creditorsListContainerTransfer');
                    if (searchInput) {
                        searchInput.addEventListener('input', function(e) {
                            const searchTerm = e.target.value.toLowerCase();
                            const creditorItems = document.querySelectorAll('.creditor-item');
                            let visibleCount = 0;
                            
                            creditorItems.forEach(item => {
                                const name = item.getAttribute('data-name') || '';
                                const phone = item.getAttribute('data-phone') || '';
                                if (name.includes(searchTerm) || phone.includes(searchTerm)) {
                                    item.style.display = 'block';
                                    visibleCount++;
                                } else {
                                    item.style.display = 'none';
                                }
                            });
                        });
                    }
                    
                    // Select creditor on click
                    window.selectCreditorForTransfer = function(id, tabId, balance) {
                        selectedCreditorId = id;
                        // Remove previous selection
                        document.querySelectorAll('.creditor-item').forEach(item => {
                            item.classList.remove('bg-indigo-100', 'border-2', 'border-indigo-300');
                            item.classList.add('bg-white');
                        });
                        // Highlight selected
                        const selectedItem = document.querySelector(`.creditor-item[data-id="${id}"]`);
                        if (selectedItem) {
                            selectedItem.classList.remove('bg-white', 'hover:bg-gray-200');
                            selectedItem.classList.add('bg-indigo-100', 'border-2', 'border-indigo-300');
                        }
                        // Show next button
                        const confirmBtn = document.querySelector('.swal2-confirm');
                        if (confirmBtn) {
                            confirmBtn.classList.remove('hidden');
                        }
                        // Auto-proceed after selection
                        setTimeout(() => {
                            if (!checkCreditSaleWithinLimit(id, balance)) {
                                return;
                            }
                            Swal.close();
                            proceedWithTransfer(tabId, id, balance);
                        }, 200);
                    };
                    
                    // If existing creditor is set, auto-select it
                    if (existingCreditorId) {
                        const existingItem = document.querySelector(`.creditor-item[data-id="${existingCreditorId}"]`);
                        if (existingItem) {
                            existingItem.click();
                        }
                    }
                }
            });
        }
        
        function showCreateCreditorModalForTransfer(tabId, currentBalance, existingCreditors) {
            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Create New Account</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Creditor Name <span class="text-red-500">*</span></label>
                            <input type="text" 
                                   id="newCreditorNameTransfer" 
                                   class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                   placeholder="Enter name" 
                                   autocomplete="off">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Phone Number (Optional)</label>
                            <input type="text" 
                                   id="newCreditorPhoneTransfer" 
                                   class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                   placeholder="Enter phone" 
                                   autocomplete="off">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Credit Limit (N$)</label>
                            <input type="number" id="newCreditorLimitTransfer" min="0" step="0.01"
                                   class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                   placeholder="0 = unlimited" value="0" autocomplete="off">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Create',
                confirmButtonClass: 'swal2-confirm-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const name = document.getElementById('newCreditorNameTransfer').value.trim();
                    const phone = document.getElementById('newCreditorPhoneTransfer').value.trim();
                    const credit_limit = parseFloat(document.getElementById('newCreditorLimitTransfer').value || '0');
                    
                    if (!name) {
                        Swal.showValidationMessage('<span class="text-red-500">Creditor name is required</span>');
                        return false;
                    }
                    
                    return { name, phone, credit_limit: isNaN(credit_limit) ? 0 : Math.max(0, credit_limit) };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create creditor via API
                    fetch('../create_creditor.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(result.value)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Account Created',
                                text: `Creditor "${data.creditor.name}" created successfully`,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                // Reload creditors and show selection modal again
                                fetch('../get_creditors_with_balances.php')
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            showCreditorSelectionForTransfer(tabId, currentBalance, data.creditors, null);
                                        }
                                    });
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to create creditor', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error creating creditor:', error);
                        Swal.fire('Error', 'Failed to create creditor', 'error');
                    });
                }
            });
        }
        
        function proceedWithTransfer(tabId, creditorId, balance) {
            if (!checkCreditSaleWithinLimit(creditorId, balance)) {
                return;
            }
            // Show due date input modal
            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Set Due Date</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3">
                            <p class="text-xs text-indigo-800 mb-1">
                                <i data-lucide="info" class="w-4 h-4 inline-block mr-1"></i>
                                The tab will be deleted after transfer.
                            </p>
                            <p class="text-xs font-semibold text-indigo-900">Balance: <span>N$${parseFloat(balance).toFixed(2)}</span></p>
                        </div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Payment Deadline:</label>
                        <input type="date" 
                               id="transferDueDate" 
                               min="${new Date().toISOString().split('T')[0]}"
                               value="${(() => { const now = new Date(); const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0); return lastDay.toISOString().split('T')[0]; })()}"
                               class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200">
                    </div>
                `,
                focusConfirm: false,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Transfer',
                cancelButtonText: 'Back',
                confirmButtonClass: 'swal2-confirm-btn bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const dueDate = document.getElementById('transferDueDate').value;
                    if (!dueDate) {
                        Swal.showValidationMessage('<span class="text-red-500">A valid due date is required</span>');
                        return false;
                    }
                    return { dueDate, creditorId, tabId };
                }
            }).then((result) => {
                if (result.dismiss === Swal.DismissReason.cancel) {
                    // Go back to creditor selection
                    openTransferToCreditSaleModal(tabId, balance, creditorId);
                    return;
                }
                if (result.isConfirmed) {
                    const { dueDate, creditorId, tabId } = result.value;
                    
                    // Final confirmation
                    Swal.fire({
                        icon: 'warning',
                        title: 'Confirm Transfer',
                        html: `
                            <p class="text-sm text-gray-700 mb-2">Are you sure you want to transfer this tab to a credit sale?</p>
                            <p class="text-sm text-gray-600">Balance: <strong>N$${parseFloat(balance).toFixed(2)}</strong></p>
                            <p class="text-sm text-gray-600">Due Date: <strong>${new Date(dueDate).toLocaleDateString()}</strong></p>
                            <p class="text-xs text-amber-600 mt-2">This action will delete the tab and update product quantities.</p>
                        `,
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Transfer',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#4F46E5',
                        cancelButtonColor: '#6B7280',
                    }).then((confirmResult) => {
                        if (confirmResult.isConfirmed) {
                            // Submit the form
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = 'view-tab.php';
                            
                            const tabIdInput = document.createElement('input');
                            tabIdInput.type = 'hidden';
                            tabIdInput.name = 'tab_id';
                            tabIdInput.value = tabId;
                            form.appendChild(tabIdInput);
                            
                            const transferInput = document.createElement('input');
                            transferInput.type = 'hidden';
                            transferInput.name = 'transfer_to_credit_sale';
                            transferInput.value = '1';
                            form.appendChild(transferInput);
                            
                            const creditorIdInput = document.createElement('input');
                            creditorIdInput.type = 'hidden';
                            creditorIdInput.name = 'creditor_id';
                            creditorIdInput.value = creditorId;
                            form.appendChild(creditorIdInput);
                            
                            const dueDateInput = document.createElement('input');
                            dueDateInput.type = 'hidden';
                            dueDateInput.name = 'due_date';
                            dueDateInput.value = dueDate;
                            form.appendChild(dueDateInput);
                            
                            document.body.appendChild(form);
                            form.submit();
                        }
                    });
                }
            });
        }
        
        // Void Tab function
        function openVoidTabModal(tabId, tabName) {
            Swal.fire({
                icon: 'warning',
                title: 'Void Tab',
                html: `
                    <div class="text-left">
                        <p class="text-sm text-gray-700 mb-3">Are you sure you want to void this tab?</p>
                        <p class="text-sm font-semibold text-gray-900 mb-2">Tab: <span class="text-gray-700">${tabName}</span></p>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 mt-3">
                            <p class="text-xs text-red-800 font-semibold mb-1">⚠️ This action cannot be undone!</p>
                            <ul class="text-xs text-red-700 space-y-1 list-disc list-inside">
                                <li>All items will be restored to stock</li>
                                <li>All payments and orders will be deleted</li>
                                <li>The tab will be permanently deleted</li>
                            </ul>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Yes, Void Tab',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#DC2626',
                cancelButtonColor: '#6B7280',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create and submit form
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'view-tab.php';
                    
                    const tabIdInput = document.createElement('input');
                    tabIdInput.type = 'hidden';
                    tabIdInput.name = 'void_tab_id';
                    tabIdInput.value = tabId;
                    form.appendChild(tabIdInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
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
    <?= tab_prepay_postpaid_modal_scripts_html() ?>
</body>
</html>

