<?php
session_start();
header('Content-Type: application/json');

// Set Namibia timezone
date_default_timezone_set('Africa/Harare');

$db = new PDO('sqlite:pos.db');
require_once __DIR__ . '/recipe_stock_helper.php';

try {
    $db->beginTransaction();

    // Validate input
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['creditor_id'], $data['total'], $data['due_date'], $data['items'])) {
        throw new Exception('Missing required fields');
    }
    
    $creditorId = $data['creditor_id'];
    $total = $data['total'];
    $dueDate = $data['due_date'];

    // Get creditor details
    $creditor = $db->prepare("SELECT * FROM creditors WHERE id = :creditorId");
    $creditor->execute([':creditorId' => $creditorId]);
    $creditor = $creditor->fetch(PDO::FETCH_ASSOC);

    if (!$creditor || $creditor['active'] != 1) {
        throw new Exception('Invalid or inactive creditor');
    }

    // Create credit sale record with Namibia time
    $stmt = $db->prepare("INSERT INTO credit_sales (creditor_id, total_amount, due_date, created_at, cashier_id) 
                         VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $creditorId, 
        $total, 
        $dueDate,
        date('Y-m-d H:i:s'), // Current Namibia time
        $_SESSION['username'] ?? 'Unknown'
    ]);
    $saleId = $db->lastInsertId();

    // Create credit sale items
    // Get buying_price and category from products table to store historical cost and check category
    $stmtGetProductInfo = $db->prepare("SELECT buying_price, category FROM products WHERE name = ?");
    $itemStmt = $db->prepare("INSERT INTO credit_sale_items (sale_id, product_name, quantity, price, buying_price)
                             VALUES (?, ?, ?, ?, ?)");
    
    // Prepare statements for updating daily stock summary with sold quantities
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

    // Prepare statement to ensure daily stock summary exists for the current day
    $stmtEnsureDailySummary = $db->prepare("
        INSERT OR IGNORE INTO daily_stock_summary 
        (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
        VALUES (
            ?,
            (SELECT id FROM products WHERE name = ?),
            0, 0, 0, 0, 0
        )
    ");

    $currentDate = date('Y-m-d');
    
    foreach ($data['items'] as $item) {
        // Get buying_price and category for this product (store historical cost and check category)
        $buyingPrice = null;
        $productCategory = null;
        $stmtGetProductInfo->execute([$item['name']]);
        $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
        $buyingPrice = $productInfo ? ($productInfo['buying_price'] ?? null) : null;
        $productCategory = $productInfo ? ($productInfo['category'] ?? null) : null;

        // Only decrease quantity if category is not "Food"
        $isFood = strtolower(trim($productCategory ?? '')) === 'food';
        $usedRecipeStock = deductRecipeStockByProductName($db, $item['name'], floatval($item['quantity']));
        if (!$isFood && !$usedRecipeStock) {
            $updateStmt = $db->prepare("UPDATE products SET quantity = quantity - ? WHERE name = ?");
            $updateStmt->execute([$item['quantity'], $item['name']]);
        }

        // Record sale item
        $itemStmt->execute([
            $saleId,
            $item['name'],
            $item['quantity'],
            $item['price'] / $item['quantity'], // Store per-item price
            $buyingPrice // Store historical buying price
        ]);
        
        // Ensure daily stock summary exists for this product and date
        $stmtEnsureDailySummary->execute([
            $currentDate,
            $item['name']
        ]);
        
        // Update daily stock summary with sold quantity immediately (even for Food, for reporting purposes)
        $stmtUpdateDailySummary->execute([
            $currentDate, // date
            $item['name'], // product name for product_id lookup
            $currentDate, $item['name'], // opening_quantity lookup
            $currentDate, $item['name'], // closing_quantity lookup  
            $currentDate, $item['name'], // received_quantity lookup
            $currentDate, $item['name'], $item['quantity'], // sold_quantity lookup and increment
            $currentDate, $item['name']  // damaged_quantity lookup
        ]);
    }

    $db->commit();
    
    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'creditor_name' => $creditor['name']
    ]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}