<?php
header('Content-Type: application/json');

// Start session
session_start();

// Set Central Africa Time timezone
date_default_timezone_set('Africa/Harare');

// Database connection
$db = new PDO('sqlite:pos.db');

// Get the raw POST data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

try {
    // Start a transaction
    $db->beginTransaction();

    // Check payment modes
    $isEftPayment = isset($data['payment_method']) && $data['payment_method'] === 'e-wallet';
    $isMixedPayment = isset($data['payment_method']) && $data['payment_method'] === 'mixed';
    
    // Determine cash received based on payment method
    if ($isEftPayment) {
        $cashReceived = 0; // EFT only
    } else if ($isMixedPayment) {
        $cashReceived = isset($data['cash_amount']) ? floatval($data['cash_amount']) : 0;
    } else {
        $cashReceived = isset($data['cash_received']) ? floatval($data['cash_received']) : 0;
    }
    
    // Insert the order into the orders table with current Namibia time
    $stmt = $db->prepare("INSERT INTO orders (total, cash_received, created_at, cashier_id) VALUES (:total, :cash_received, :created_at, :cashier_id)");
    $stmt->execute([
        ':total' => $data['total'],
        ':cash_received' => $cashReceived,
        ':created_at' => date('Y-m-d H:i:s'), // Current Namibia time
        ':cashier_id' => $_SESSION['username'] // Add cashier username from session
    ]);

    $orderId = $db->lastInsertId();

    // Insert order items and update inventory
    // Get buying_price and category from products table to store historical cost and check category
    $stmtGetProductInfo = $db->prepare("SELECT buying_price, category FROM products WHERE name = :product_name");
    $stmtOrderItems = $db->prepare("INSERT INTO order_items (order_id, product_name, quantity, price, buying_price) VALUES (:order_id, :product_name, :quantity, :price, :buying_price)");
    $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity - :quantity WHERE name = :product_name");
    
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
        if ($item['name'] !== 'EFT Income') {
            $stmtGetProductInfo->execute([':product_name' => $item['name']]);
            $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
            $buyingPrice = $productInfo ? ($productInfo['buying_price'] ?? null) : null;
            $productCategory = $productInfo ? ($productInfo['category'] ?? null) : null;
        }
        
        $stmtOrderItems->execute([
            ':order_id' => $orderId,
            ':product_name' => $item['name'],
            ':quantity' => $item['quantity'],
            ':price' => $item['price'],
            ':buying_price' => $buyingPrice
        ]);

        // Skip inventory updates and daily stock summary for EFT income items and Food category
        if ($item['name'] !== 'EFT Income') {
            // Only decrease quantity if category is not "Food"
            $isFood = strtolower(trim($productCategory ?? '')) === 'food';
            if (!$isFood) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $item['name']
                ]);
            }
            
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
    }

    // If this is an EFT or MIXED payment, store the EFT payment details
    if ($isEftPayment || $isMixedPayment) {
        $stmtEftPayment = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (:order_id, :transaction_ref, :wallet_provider, :amount, :cashier_id, :payment_date)");
        $eftAmount = $isEftPayment ? floatval($data['total']) : (isset($data['eft_amount']) ? floatval($data['eft_amount']) : 0);
        if ($eftAmount > 0) {
            $stmtEftPayment->execute([
                ':order_id' => $orderId,
                ':transaction_ref' => $data['transaction_ref'] ?? null,
                ':wallet_provider' => $data['wallet_provider'] ?? null,
                ':amount' => $eftAmount,
                ':cashier_id' => $_SESSION['username'],
                ':payment_date' => date('Y-m-d H:i:s')
            ]);
        }

        // Persist mixed payment split for audit if applicable
        if ($isMixedPayment) {
            $db->exec("CREATE TABLE IF NOT EXISTS mixed_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                eft_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                eft_transaction_ref TEXT,
                eft_wallet_provider TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                cashier_id TEXT,
                FOREIGN KEY(order_id) REFERENCES orders(id)
            )");

            $stmtMixed = $db->prepare("INSERT INTO mixed_payments (order_id, cash_amount, eft_amount, eft_transaction_ref, eft_wallet_provider, cashier_id) VALUES (:order_id, :cash_amount, :eft_amount, :eft_transaction_ref, :eft_wallet_provider, :cashier_id)");
            $stmtMixed->execute([
                ':order_id' => $orderId,
                ':cash_amount' => $cashReceived,
                ':eft_amount' => $eftAmount,
                ':eft_transaction_ref' => $data['transaction_ref'] ?? null,
                ':eft_wallet_provider' => $data['wallet_provider'] ?? null,
                ':cashier_id' => $_SESSION['username']
            ]);
        }
    }

    // Commit the transaction
    $db->commit();

    echo json_encode(['success' => true, 'order_id' => $orderId]);
} catch (Exception $e) {
    // Rollback the transaction in case of error
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
