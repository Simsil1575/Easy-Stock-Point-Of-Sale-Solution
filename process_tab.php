<?php
session_start();
header('Content-Type: application/json');

// Set Namibia timezone
date_default_timezone_set('Africa/Harare');

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once __DIR__ . '/recipe_stock_helper.php';
configureSqlitePdo($db);
require_once __DIR__ . '/tab_balance_helper.php';

// Create tabs and tab_items tables if they don't exist
try {
    // Create tabs table - cashier_id as TEXT to store username
    $db->exec("
        CREATE TABLE IF NOT EXISTS tabs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creditor_id INTEGER,
            tab_name TEXT NOT NULL,
            opening_balance DECIMAL(10,2) DEFAULT 0.00,
            current_balance DECIMAL(10,2) DEFAULT 0.00,
            status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'closed')),
            opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME,
            closed_by INTEGER,
            notes TEXT,
            cashier_id TEXT,
            FOREIGN KEY(creditor_id) REFERENCES creditors(id)
        )
    ");
    
    // Try to add cashier_id as TEXT if it doesn't exist or alter if it's INTEGER
    try {
        $db->exec("ALTER TABLE tabs ADD COLUMN cashier_id TEXT");
    } catch (PDOException $e) {
        // Column already exists, might be INTEGER - SQLite will accept TEXT values anyway
    }
    
    // Create tab_items table - added_by as TEXT to store username
    $db->exec("
        CREATE TABLE IF NOT EXISTS tab_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tab_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            added_by TEXT,
            FOREIGN KEY(tab_id) REFERENCES tabs(id) ON DELETE CASCADE
        )
    ");
    
    // Try to add added_by as TEXT if it doesn't exist or alter if it's INTEGER
    try {
        $db->exec("ALTER TABLE tab_items ADD COLUMN added_by TEXT");
    } catch (PDOException $e) {
        // Column already exists, might be INTEGER - SQLite will accept TEXT values anyway
    }
} catch (PDOException $e) {
    error_log("Error creating tabs tables: " . $e->getMessage());
}

try {
    $db->beginTransaction();

    // Validate input
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['table_id'], $data['table_name'], $data['total'], $data['items'])) {
        throw new Exception('Missing required fields');
    }
    
    $tableId = $data['table_id'];
    $tableName = $data['table_name'];
    $total = floatval($data['total']);
    $cashierUsername = $_SESSION['username'] ?? 'Unknown';

    // Check if tab exists for this table (check both open and closed tabs)
    $tabStmt = $db->prepare("SELECT * FROM tabs WHERE tab_name = ? ORDER BY opened_at DESC LIMIT 1");
    $tabStmt->execute([$tableName]);
    $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tab) {
        // Create new tab for this table - store username for consistent tracking
        ensureTabGratuityColumns($db);
        $defaultGratuityOn = tab_default_gratuity_enabled_on_create($db);
        $createTabStmt = $db->prepare("INSERT INTO tabs (tab_name, opening_balance, current_balance, cashier_id, gratuity_enabled) VALUES (?, 0, 0, ?, ?)");
        $createTabStmt->execute([$tableName, $cashierUsername, $defaultGratuityOn]);
        $tabId = $db->lastInsertId();
        
        // Fetch the newly created tab
        $tabStmt = $db->prepare("SELECT * FROM tabs WHERE id = ?");
        $tabStmt->execute([$tabId]);
        $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $tabId = $tab['id'];
        
        // If tab is closed, reopen it automatically
        if ($tab['status'] === 'closed') {
            $reopenStmt = $db->prepare("UPDATE tabs SET status = 'open', closed_at = NULL, closed_by = NULL WHERE id = ?");
            $reopenStmt->execute([$tabId]);
        }
    }

    // Prepare statements for checking and inserting/updating tab items
    $checkItemStmt = $db->prepare("
        SELECT ti.id, ti.quantity, ti.price,
               COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) as paid_amount
        FROM tab_items ti
        WHERE ti.tab_id = ? 
          AND ti.product_name = ? 
          AND ti.price = ?
          AND (
              (ti.quantity * ti.price) < 0
              OR COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) < (ti.quantity * ti.price)
          )
        LIMIT 1
    ");
    $insertItemStmt = $db->prepare("INSERT INTO tab_items (tab_id, product_name, quantity, price, added_by) VALUES (?, ?, ?, ?, ?)");
    $updateItemStmt = $db->prepare("UPDATE tab_items SET quantity = quantity + ? WHERE id = ?");
    
    // Prepare statements for updating product quantities (similar to process_order.php)
    $stmtGetProductInfo = $db->prepare("SELECT buying_price, category FROM products WHERE name = ?");
    $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity - ? WHERE name = ?");
    
    // Create tab_item_payments table if it doesn't exist (for the query above)
    try {
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
    } catch (PDOException $e) {
        // Table might already exist, ignore
    }
    
    foreach ($data['items'] as $item) {
        $quantity = intval($item['quantity']);
        $price = floatval($item['price']);
        $unitPrice = $price / $quantity; // Calculate unit price

        // Check if an unpaid item with the same product name and price already exists
        $checkItemStmt->execute([$tabId, $item['name'], $unitPrice]);
        $existingItem = $checkItemStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingItem) {
            // Merge quantities: update existing item
            $updateItemStmt->execute([$quantity, $existingItem['id']]);
        } else {
            // Insert new item - store username for consistent tracking
            $insertItemStmt->execute([
                $tabId,
                $item['name'],
                $quantity,
                $unitPrice, // Store unit price
                $cashierUsername
            ]);
        }
        
        // Reduce product quantities (similar to process_order.php)
        // Skip inventory updates for non-stock tab lines (EFT, lay-bye, prepayment credit) and Food category
        if ($item['name'] !== 'EFT Income' && $item['name'] !== 'Lay-bye Payment' && !is_tab_non_inventory_tab_line_name($item['name'])) {
            $stmtGetProductInfo->execute([$item['name']]);
            $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
            $productCategory = $productInfo ? ($productInfo['category'] ?? null) : null;
            deductRecipeStockByProductName($db, $item['name'], floatval($quantity));
            
            // Only decrease main product quantity if category is not "Food" (ingredients always deducted above when linked)
            $isFood = strtolower(trim($productCategory ?? '')) === 'food';
            if (!$isFood) {
                $stmtUpdateInventory->execute([$quantity, $item['name']]);
            }
        }
    }

    ensureTabPrepaidBalanceColumn($db);
    recalculateTabBalance($db, $tabId);

    $db->commit();
    
    echo json_encode([
        'success' => true,
        'tab_id' => $tabId,
        'tab_name' => $tableName,
        'message' => 'Items added to tab successfully'
    ]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

