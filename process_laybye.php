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
require_once __DIR__ . '/laybye_order_helper.php';

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
    if (!$data || !isset($data['creditor_id'], $data['items'], $data['total'])) {
        throw new Exception('Missing required fields');
    }

    $creditorId = (int) $data['creditor_id'];
    $total = round(floatval($data['total']), 2);
    $deposit = isset($data['deposit']) ? round(floatval($data['deposit']), 2) : 0;
    $planFrequency = $data['plan_frequency'] ?? 'weekly';
    if (!in_array($planFrequency, ['weekly', 'monthly'], true)) {
        $planFrequency = 'weekly';
    }
    $planPeriod = isset($data['plan_period']) ? (int) $data['plan_period'] : 0;
    if ($planPeriod < 1) {
        $planPeriod = $planFrequency === 'monthly' ? 4 : 12;
    }
    if ($planPeriod > 120) {
        $planPeriod = 120;
    }
    $installmentAmount = round(floatval($data['installment_amount'] ?? 0), 2);
    $nextDueDate = $data['next_due_date'] ?? null;
    if ($nextDueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextDueDate)) {
        $nextDueDate = null;
    }

    $paymentMethod = strtolower(trim($data['payment_method'] ?? 'cash'));
    if (!in_array($paymentMethod, ['cash', 'eft', 'mixed'], true)) {
        $paymentMethod = 'cash';
    }
    $transactionRef = $data['transaction_ref'] ?? '';
    $walletProvider = $data['wallet_provider'] ?? '';
    $cashAmount = round(floatval($data['cash_amount'] ?? 0), 2);
    $eftAmount = round(floatval($data['eft_amount'] ?? 0), 2);
    $cashTenderedInput = round(floatval($data['cash_tendered'] ?? 0), 2);

    if ($total <= 0) {
        throw new Exception('Total must be greater than zero');
    }
    if ($deposit < 0 || $deposit > $total + 0.01) {
        throw new Exception('Invalid deposit amount');
    }
    if ($installmentAmount < 0) {
        throw new Exception('Installment amount cannot be negative');
    }

    $creditor = $db->prepare("SELECT * FROM creditors WHERE id = ?");
    $creditor->execute([$creditorId]);
    $creditor = $creditor->fetch(PDO::FETCH_ASSOC);
    if (!$creditor || (int) ($creditor['active'] ?? 1) !== 1) {
        throw new Exception('Invalid or inactive creditor');
    }

    $computed = 0;
    foreach ($data['items'] as $item) {
        $computed += round(floatval($item['price'] ?? 0), 2);
    }
    if (abs($computed - $total) > 0.02) {
        throw new Exception('Cart total does not match items');
    }

    $isMixedPayment = $paymentMethod === 'mixed';
    $cashReceivedForOrder = 0;
    if ($deposit > 0.01) {
        if ($isMixedPayment && abs(($cashAmount + $eftAmount) - $deposit) > 0.02) {
            throw new Exception('Cash + EFT must equal deposit amount');
        }
        if ($paymentMethod === 'cash') {
            $cashReceivedForOrder = $cashTenderedInput > 0 ? $cashTenderedInput : $deposit;
            if ($cashReceivedForOrder + 0.001 < $deposit) {
                throw new Exception('Cash tendered must be at least the deposit amount');
            }
        } elseif ($isMixedPayment) {
            $cashReceivedForOrder = $cashAmount;
        }
    }

    $cashierUsername = $_SESSION['username'] ?? 'Unknown';

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

    $balanceDue = round($total - $deposit, 2);
    $accStmt = $db->prepare("
        INSERT INTO laybye_accounts (creditor_id, reference, total_amount, balance_due, deposit_amount, plan_frequency, plan_period, installment_amount, next_due_date, status, cashier_id)
        VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, 'active', ?)
    ");
    $accStmt->execute([
        $creditorId,
        $total,
        $balanceDue,
        $deposit,
        $planFrequency,
        $planPeriod,
        $installmentAmount,
        $nextDueDate,
        $cashierUsername,
    ]);
    $laybyeId = (int) $db->lastInsertId();

    $ref = 'LB-' . $laybyeId;
    $db->prepare("UPDATE laybye_accounts SET reference = ? WHERE id = ?")->execute([$ref, $laybyeId]);

    foreach ($data['items'] as $item) {
        $name = $item['name'];
        $qty = (int) $item['quantity'];
        $lineTotal = floatval($item['price']);
        if ($qty <= 0) {
            throw new Exception('Invalid quantity');
        }
        $unitPrice = $lineTotal / $qty;

        if ($name === 'Cart Discount' || $name === 'Gratuity') {
            $itemStmt->execute([$laybyeId, $name, $qty, $unitPrice, null, $cashierUsername]);
            continue;
        }

        $buyingPrice = null;
        $productCategory = null;
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

    $orderId = null;
    if ($deposit > 0.01) {
        $orderId = laybyeCreatePaymentOrder(
            $db,
            $deposit,
            $cashReceivedForOrder,
            $paymentMethod,
            $transactionRef,
            $walletProvider,
            $cashAmount,
            $eftAmount,
            $cashierUsername
        );

        $payStmt = $db->prepare("
            INSERT INTO laybye_payments (laybye_id, amount, payment_method, transaction_ref, wallet_provider, cashier_id, order_id, payment_kind)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'deposit')
        ");
        $payStmt->execute([$laybyeId, $deposit, $paymentMethod, $transactionRef, $walletProvider, $cashierUsername, $orderId]);
    }

    if ($balanceDue <= 0.01) {
        $db->prepare("UPDATE laybye_accounts SET status = 'completed', balance_due = 0, closed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $laybyeId]);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'laybye_id' => $laybyeId,
        'reference' => $ref,
        'creditor_name' => $creditor['name'],
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
