<?php
require_once __DIR__ . '/../cashier_helper.php';
require_once __DIR__ . '/../terminal_helper.php';
require_once __DIR__ . '/../recipe_stock_helper.php';
requireApiSession();
header('Content-Type: application/json');

date_default_timezone_set('Africa/Harare');

try {
    $scriptDir = dirname(__FILE__);
    $dbPath = $scriptDir . '/../pos.db';
    $db = new PDO('sqlite:' . $dbPath);
    configureSqlitePdo($db);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = json_decode(file_get_contents('php://input'), true);

    if (
        !$input
        || !isset($input['order_id'])
        || empty($input['return_items'])
        || empty($input['exchange_items'])
    ) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid exchange data',
        ]);
        exit;
    }

    $orderId = intval($input['order_id']);
    $returnItems = $input['return_items'];
    $exchangeItems = $input['exchange_items'];
    $reason = isset($input['reason']) ? trim((string) $input['reason']) : 'Product exchange';
    $returnTotal = round(floatval($input['return_total'] ?? 0), 2);
    $exchangeTotal = round(floatval($input['exchange_total'] ?? 0), 2);
    $difference = round(floatval($input['difference'] ?? ($exchangeTotal - $returnTotal)), 2);
    $cashReceived = round(floatval($input['cash_received'] ?? max(0, $difference)), 2);
    $cashierId = $_SESSION['username'] ?? 'Unknown';

    ensureTerminalSchema($db);
    $terminal = resolveTerminalFromRequest(is_array($input) ? $input : [], $db);
    $allowNegative = isSkipStockChecks($db);

    foreach ([
        "CREATE TABLE IF NOT EXISTS exchanges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            new_order_id INTEGER,
            return_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            exchange_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            difference DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason TEXT,
            cashier_id TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            terminal_mac TEXT,
            terminal_name TEXT
        )",
        "CREATE TABLE IF NOT EXISTS exchange_return_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exchange_id INTEGER NOT NULL,
            order_item_id INTEGER,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            buying_price DECIMAL(10,2) DEFAULT 0,
            FOREIGN KEY(exchange_id) REFERENCES exchanges(id)
        )",
        "CREATE TABLE IF NOT EXISTS exchange_new_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exchange_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            buying_price DECIMAL(10,2) DEFAULT 0,
            FOREIGN KEY(exchange_id) REFERENCES exchanges(id)
        )",
    ] as $migrateSql) {
        $db->exec($migrateSql);
    }

    $db->beginTransaction();

    try {
        $stmtExchange = $db->prepare("
            INSERT INTO exchanges (order_id, return_total, exchange_total, difference, reason, cashier_id, created_at, terminal_mac, terminal_name)
            VALUES (:order_id, :return_total, :exchange_total, :difference, :reason, :cashier_id, datetime('now', 'localtime'), :terminal_mac, :terminal_name)
        ");
        $stmtExchange->execute([
            ':order_id' => $orderId,
            ':return_total' => $returnTotal,
            ':exchange_total' => $exchangeTotal,
            ':difference' => $difference,
            ':reason' => $reason,
            ':cashier_id' => $cashierId,
            ':terminal_mac' => $terminal['mac'],
            ':terminal_name' => $terminal['name'],
        ]);
        $exchangeId = (int) $db->lastInsertId();

        $stmtReturnItem = $db->prepare("
            INSERT INTO exchange_return_items (exchange_id, order_item_id, product_name, quantity, price, buying_price)
            VALUES (:exchange_id, :order_item_id, :product_name, :quantity, :price, :buying_price)
        ");
        $stmtNewItem = $db->prepare("
            INSERT INTO exchange_new_items (exchange_id, product_name, quantity, price, buying_price)
            VALUES (:exchange_id, :product_name, :quantity, :price, :buying_price)
        ");
        $stmtOrderItems = $db->prepare("
            UPDATE order_items
            SET quantity = quantity - :refund_qty
            WHERE id = :order_item_id AND quantity >= :refund_qty
        ");

        foreach ($returnItems as $item) {
            $orderItemId = isset($item['order_item_id']) ? intval($item['order_item_id']) : null;
            $returnQty = intval($item['quantity']);
            $productName = $item['product_name'];

            $stmtReturnItem->execute([
                ':exchange_id' => $exchangeId,
                ':order_item_id' => $orderItemId,
                ':product_name' => $productName,
                ':quantity' => $returnQty,
                ':price' => $item['price'],
                ':buying_price' => isset($item['buying_price']) ? $item['buying_price'] : 0,
            ]);

            if ($orderItemId) {
                $stmtOrderItems->execute([
                    ':refund_qty' => $returnQty,
                    ':order_item_id' => $orderItemId,
                ]);

                $checkQtyStmt = $db->prepare("
                    UPDATE order_items
                    SET quantity = 0
                    WHERE id = :order_item_id AND quantity < 0
                ");
                $checkQtyStmt->bindValue(':order_item_id', $orderItemId, PDO::PARAM_INT);
                $checkQtyStmt->execute();
            }

            restoreSaleLineStock($db, $productName, floatval($returnQty));
        }

        $recalcStmt = $db->prepare("
            UPDATE orders
            SET total = (
                SELECT COALESCE(SUM(quantity * price), 0)
                FROM order_items
                WHERE order_id = :order_id AND quantity > 0
            )
            WHERE id = :order_id
        ");
        $recalcStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $recalcStmt->execute();

        $checkOrderStmt = $db->prepare('SELECT total FROM orders WHERE id = :order_id');
        $checkOrderStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $checkOrderStmt->execute();
        $orderTotal = $checkOrderStmt->fetchColumn();

        if ($orderTotal == 0 || $orderTotal === null) {
            $deleteOrderItemsStmt = $db->prepare('DELETE FROM order_items WHERE order_id = :order_id AND quantity = 0');
            $deleteOrderItemsStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteOrderItemsStmt->execute();

            foreach (['eft_payments', 'mixed_payments', 'tab_payments'] as $paymentTable) {
                $deletePaymentStmt = $db->prepare("DELETE FROM {$paymentTable} WHERE order_id = :order_id");
                $deletePaymentStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $deletePaymentStmt->execute();
            }

            $deleteOrderStmt = $db->prepare('DELETE FROM orders WHERE id = :order_id');
            $deleteOrderStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteOrderStmt->execute();
        } else {
            $deleteZeroQtyStmt = $db->prepare('DELETE FROM order_items WHERE order_id = :order_id AND quantity = 0');
            $deleteZeroQtyStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteZeroQtyStmt->execute();
        }

        $stmtNewOrder = $db->prepare("
            INSERT INTO orders (total, cash_received, created_at, cashier_id, gratuity_amount, gratuity_percent_applied, gratuity_included_in_total, terminal_mac, terminal_name)
            VALUES (:total, :cash_received, :created_at, :cashier_id, 0, NULL, 1, :terminal_mac, :terminal_name)
        ");
        $stmtNewOrder->execute([
            ':total' => $exchangeTotal,
            ':cash_received' => $cashReceived,
            ':created_at' => date('Y-m-d H:i:s'),
            ':cashier_id' => $cashierId,
            ':terminal_mac' => $terminal['mac'],
            ':terminal_name' => $terminal['name'],
        ]);
        $newOrderId = (int) $db->lastInsertId();

        $updateExchangeOrder = $db->prepare('UPDATE exchanges SET new_order_id = :new_order_id WHERE id = :id');
        $updateExchangeOrder->execute([
            ':new_order_id' => $newOrderId,
            ':id' => $exchangeId,
        ]);

        $stmtGetProductInfo = $db->prepare('SELECT buying_price, category FROM products WHERE name = :product_name');
        $stmtOrderItemsInsert = $db->prepare("
            INSERT INTO order_items (order_id, product_name, quantity, price, buying_price)
            VALUES (:order_id, :product_name, :quantity, :price, :buying_price)
        ");
        $stmtEnsureDailySummary = $db->prepare("
            INSERT OR IGNORE INTO daily_stock_summary
            (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
            VALUES (
                ?,
                (SELECT id FROM products WHERE name = ?),
                0, 0, 0, 0, 0
            )
        ");
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
        $currentDate = date('Y-m-d');

        foreach ($exchangeItems as $item) {
            $productName = $item['product_name'];
            $quantity = intval($item['quantity']);
            $unitPrice = floatval($item['price']);
            $lineTotal = round($unitPrice * $quantity, 2);

            $buyingPrice = isset($item['buying_price']) ? $item['buying_price'] : null;
            $stmtGetProductInfo->execute([':product_name' => $productName]);
            $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
            if ($productInfo && $buyingPrice === null) {
                $buyingPrice = $productInfo['buying_price'] ?? null;
            }

            $stmtNewItem->execute([
                ':exchange_id' => $exchangeId,
                ':product_name' => $productName,
                ':quantity' => $quantity,
                ':price' => $unitPrice,
                ':buying_price' => $buyingPrice ?? 0,
            ]);

            $stmtOrderItemsInsert->execute([
                ':order_id' => $newOrderId,
                ':product_name' => $productName,
                ':quantity' => $quantity,
                ':price' => $lineTotal,
                ':buying_price' => $buyingPrice,
            ]);

            deductRecipeStockByProductName($db, $productName, floatval($quantity), $allowNegative);
            deductProductStockByName($db, $productName, floatval($quantity), $allowNegative);

            $stmtEnsureDailySummary->execute([$currentDate, $productName]);
            $stmtUpdateDailySummary->execute([
                $currentDate,
                $productName,
                $currentDate, $productName,
                $currentDate, $productName,
                $currentDate, $productName,
                $currentDate, $productName, $quantity,
                $currentDate, $productName,
            ]);
        }

        // Exchange differences are reflected in order totals (original order reduced + new order).
        // Do not post cash_transactions for exchanges — that would double-count till movement.

        $db->commit();

        echo json_encode([
            'success' => true,
            'exchange_id' => $exchangeId,
            'new_order_id' => $newOrderId,
            'difference' => $difference,
            'message' => 'Exchange processed successfully',
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
    ]);
}
