<?php
session_start();
header('Content-Type: application/json');

date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once __DIR__ . '/manager_pin_helper.php';
require_once __DIR__ . '/recipe_stock_helper.php';
require_once __DIR__ . '/ensure_laybye_schema.php';

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensureLaybyeSchema($db);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['laybye_id']) || empty($data['item_id'])) {
        throw new Exception('Missing laybye_id or item_id');
    }
    $pin = trim((string) ($data['manager_pin'] ?? ''));
    if (!verifyManagerVoidPin($pin)) {
        throw new Exception(
            managerVoidPinIsConfigured()
                ? 'Invalid manager PIN.'
                : 'Manager void PIN is not set. Set it under Settings.'
        );
    }

    $laybyeId = (int) $data['laybye_id'];
    $itemId = (int) $data['item_id'];

    $acc = $db->prepare('SELECT * FROM laybye_accounts WHERE id = ?');
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

    $itemStmt = $db->prepare('SELECT * FROM laybye_items WHERE id = ? AND laybye_id = ?');
    $itemStmt->execute([$itemId, $laybyeId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new Exception('Item not found');
    }

    $name = $item['product_name'];
    $qty = (int) $item['quantity'];
    $unitPrice = floatval($item['price']);
    $lineTotal = round($qty * $unitPrice, 2);
    if ($lineTotal <= 0) {
        throw new Exception('Invalid line total');
    }

    $stmtGetProductInfo = $db->prepare('SELECT category FROM products WHERE name = ?');
    $stmtGetProductInfo->execute([$name]);
    $info = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
    $cat = $info ? ($info['category'] ?? '') : '';
    $isFood = strtolower(trim($cat)) === 'food';

    $addedTs = $item['added_at'] ?? null;
    $summaryDate = $addedTs ? date('Y-m-d', strtotime((string) $addedTs)) : date('Y-m-d');

    $resolveProductStmt = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');

    $db->beginTransaction();

    restoreRecipeStockByProductName($db, $name, floatval($qty));
    if (!$isFood) {
        $db->prepare('UPDATE products SET quantity = quantity + ? WHERE name = ?')->execute([$qty, $name]);
    }

    $resolveProductStmt->execute([$name]);
    if ($resolveProductStmt->fetchColumn()) {
        $db->prepare("
            INSERT OR IGNORE INTO daily_stock_summary 
            (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
            VALUES (?, (SELECT id FROM products WHERE name = ?), 0, 0, 0, 0, 0)
        ")->execute([$summaryDate, $name]);
        $db->prepare("
            UPDATE daily_stock_summary 
            SET sold_quantity = CASE WHEN sold_quantity - ? < 0 THEN 0 ELSE sold_quantity - ? END
            WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)
        ")->execute([$qty, $qty, $summaryDate, $name]);
    }

    $db->prepare('DELETE FROM laybye_items WHERE id = ? AND laybye_id = ?')->execute([$itemId, $laybyeId]);

    $newTotal = round(max(0, floatval($account['total_amount']) - $lineTotal), 2);
    $newBalance = round(max(0, floatval($account['balance_due']) - $lineTotal), 2);
    $db->prepare('UPDATE laybye_accounts SET total_amount = ?, balance_due = ? WHERE id = ?')
        ->execute([$newTotal, $newBalance, $laybyeId]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'total_amount' => $newTotal,
        'balance_due' => $newBalance,
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
