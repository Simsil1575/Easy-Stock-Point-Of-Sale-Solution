<?php
require_once __DIR__ . '/cashier_helper.php';
requireApiSession();
header('Content-Type: application/json');

date_default_timezone_set('Africa/Harare');

$db = new PDO('sqlite:pos.db');
require_once __DIR__ . '/recipe_stock_helper.php';
require_once __DIR__ . '/credit_limit_helper.php';
require_once __DIR__ . '/terminal_helper.php';
configureSqlitePdo($db);

try {
    $db->beginTransaction();

    $allowNegative = isSkipStockChecks($db);

    $data = json_decode(file_get_contents('php://input'), true);
    ensureTerminalSchema($db);
    $terminal = resolveTerminalFromRequest(is_array($data) ? $data : [], $db);
    if (!isset($data['creditor_id'], $data['total'], $data['due_date'], $data['items'])) {
        throw new Exception('Missing required fields');
    }

    $creditorId = $data['creditor_id'];
    $total = $data['total'];
    $dueDate = $data['due_date'];

    $creditor = $db->prepare("SELECT * FROM creditors WHERE id = :creditorId");
    $creditor->execute([':creditorId' => $creditorId]);
    $creditor = $creditor->fetch(PDO::FETCH_ASSOC);

    if (!$creditor || $creditor['active'] != 1) {
        throw new Exception('Invalid or inactive creditor');
    }

    assertCreditSaleWithinLimit($db, (int) $creditorId, (float) $total);

    $stmt = $db->prepare("INSERT INTO credit_sales (creditor_id, total_amount, due_date, created_at, cashier_id, terminal_mac, terminal_name)
                         VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $creditorId,
        $total,
        $dueDate,
        date('Y-m-d H:i:s'),
        $_SESSION['username'] ?? 'Unknown',
        $terminal['mac'],
        $terminal['name'],
    ]);
    $saleId = $db->lastInsertId();

    $stmtGetProductInfo = $db->prepare("SELECT buying_price, category FROM products WHERE name = ?");
    $itemStmt = $db->prepare("INSERT INTO credit_sale_items (sale_id, product_name, quantity, price, buying_price)
                             VALUES (?, ?, ?, ?, ?)");

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
        VALUES (
            ?,
            (SELECT id FROM products WHERE name = ?),
            0, 0, 0, 0, 0
        )
    ");

    $currentDate = date('Y-m-d');

    foreach ($data['items'] as $item) {
        $buyingPrice = null;
        $productCategory = null;
        $stmtGetProductInfo->execute([$item['name']]);
        $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
        $buyingPrice = $productInfo ? ($productInfo['buying_price'] ?? null) : null;
        $productCategory = $productInfo ? ($productInfo['category'] ?? null) : null;

        $skipStock = ($item['name'] === 'Cart Discount' || $item['name'] === 'Gratuity');

        if (!$skipStock) {
            $isFood = strtolower(trim($productCategory ?? '')) === 'food';
            deductRecipeStockByProductName($db, $item['name'], floatval($item['quantity']), $allowNegative);
            if (!$isFood) {
                deductProductStockByName($db, $item['name'], floatval($item['quantity']), $allowNegative);
            }
        }

        $itemStmt->execute([
            $saleId,
            $item['name'],
            $item['quantity'],
            $item['price'] / $item['quantity'],
            $buyingPrice
        ]);

        if (!$skipStock) {
            $stmtEnsureDailySummary->execute([
                $currentDate,
                $item['name']
            ]);

            $stmtUpdateDailySummary->execute([
                $currentDate,
                $item['name'],
                $currentDate, $item['name'],
                $currentDate, $item['name'],
                $currentDate, $item['name'],
                $currentDate, $item['name'], $item['quantity'],
                $currentDate, $item['name']
            ]);
        }
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'creditor_name' => $creditor['name']
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
