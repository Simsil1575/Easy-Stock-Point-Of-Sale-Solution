<?php
session_start();
header('Content-Type: application/json');

date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/recipe_stock_helper.php';
require_once __DIR__ . '/ensure_laybye_schema.php';

ensureLaybyeSchema($db);

try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN skip_stock_checks BOOLEAN NOT NULL DEFAULT 0");
} catch (PDOException $e) {
    // column exists
}
$settingsRow = $db->query("SELECT skip_stock_checks FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$skipStockChecks = $settingsRow && (int) ($settingsRow['skip_stock_checks'] ?? 0) === 1;

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['laybye_id']) || empty($data['items'])) {
        throw new Exception('Missing laybye_id or items');
    }
    $laybyeId = (int) $data['laybye_id'];
    $cashierUsername = $_SESSION['username'] ?? 'Unknown';

    $acc = $db->prepare("SELECT * FROM laybye_accounts WHERE id = ?");
    $acc->execute([$laybyeId]);
    $account = $acc->fetch(PDO::FETCH_ASSOC);
    if (!$account || $account['status'] !== 'active') {
        throw new Exception('Lay-bye not found or not active');
    }
    $roleC = strtolower($_SESSION['role'] ?? '');
    $canMutate = in_array($roleC, ['admin', 'manager'], true)
        || (string) ($account['cashier_id'] ?? '') === (string) ($_SESSION['user_id'] ?? '')
        || (string) ($account['cashier_id'] ?? '') === (string) ($_SESSION['username'] ?? '');
    if (!$canMutate) {
        throw new Exception('Not allowed');
    }

    $addTotal = 0;
    foreach ($data['items'] as $item) {
        $addTotal += round(floatval($item['price'] ?? 0), 2);
    }
    if ($addTotal <= 0) {
        throw new Exception('Invalid items total');
    }

    $stmtGetProductInfo = $db->prepare("SELECT buying_price, category, quantity FROM products WHERE name = ?");
    $itemStmt = $db->prepare("INSERT INTO laybye_items (laybye_id, product_name, quantity, price, buying_price, added_by) VALUES (?, ?, ?, ?, ?, ?)");

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

    $db->beginTransaction();

    foreach ($data['items'] as $item) {
        $name = $item['name'];
        $qty = (int) $item['quantity'];
        $lineTotal = floatval($item['price']);
        if ($qty <= 0) {
            throw new Exception('Invalid quantity');
        }
        $unitPrice = $lineTotal / $qty;

        $stmtGetProductInfo->execute([$name]);
        $productInfo = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
        $buyingPrice = $productInfo ? ($productInfo['buying_price'] ?? null) : null;
        $productCategory = $productInfo ? ($productInfo['category'] ?? null) : null;

        laybyeAssertStockForAddItem($db, $name, $qty, $productInfo, $skipStockChecks);

        $isFood = strtolower(trim($productCategory ?? '')) === 'food';
        deductRecipeStockByProductName($db, $name, floatval($qty));
        if (!$isFood) {
            $updateStmt = $db->prepare("UPDATE products SET quantity = quantity - ? WHERE name = ?");
            $updateStmt->execute([$qty, $name]);
        }

        $itemStmt->execute([$laybyeId, $name, $qty, $unitPrice, $buyingPrice, $cashierUsername]);

        $stmtEnsureDailySummary->execute([$currentDate, $name]);
        $stmtUpdateDailySummary->execute([
            $currentDate, $name,
            $currentDate, $name,
            $currentDate, $name,
            $currentDate, $name,
            $currentDate, $name, $qty,
            $currentDate, $name,
        ]);
    }

    $newTotal = round(floatval($account['total_amount']) + $addTotal, 2);
    $newBalance = round(floatval($account['balance_due']) + $addTotal, 2);
    $db->prepare("UPDATE laybye_accounts SET total_amount = ?, balance_due = ? WHERE id = ?")
        ->execute([$newTotal, $newBalance, $laybyeId]);

    $db->commit();

    echo json_encode(['success' => true, 'total_amount' => $newTotal, 'balance_due' => $newBalance]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
