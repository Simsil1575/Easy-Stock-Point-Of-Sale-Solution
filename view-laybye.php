<?php
session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ./');
    exit;
}

$pdo = new PDO('sqlite:active.db');
if ($pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn() == 0) {
    header('Location: settings');
    exit;
}

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/ensure_laybye_schema.php';
require_once __DIR__ . '/laybye_order_helper.php';
require_once __DIR__ . '/recipe_stock_helper.php';
require_once __DIR__ . '/laybye_receipt_helper.php';
require_once __DIR__ . '/manager_pin_helper.php';

ensureLaybyeSchema($db);

$cashierUsername = $_SESSION['username'] ?? 'Unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel_laybye'])) {
        $laybyeId = (int) $_POST['laybye_id'];
        $accStmt = $db->prepare('SELECT * FROM laybye_accounts WHERE id = ?');
        $accStmt->execute([$laybyeId]);
        $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc || $acc['status'] !== 'active') {
            $_SESSION['error'] = 'Lay-bye cannot be cancelled';
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }
        $roleC = strtolower($_SESSION['role'] ?? '');
        $canMutate = in_array($roleC, ['admin', 'manager'], true)
            || (string) ($acc['cashier_id'] ?? '') === (string) ($_SESSION['user_id'] ?? '')
            || (string) ($acc['cashier_id'] ?? '') === (string) ($_SESSION['username'] ?? '');
        if (!$canMutate) {
            $_SESSION['error'] = 'Not allowed';
            header('Location: laybye.php');
            exit;
        }
        $pin = trim((string) ($_POST['manager_pin'] ?? ''));
        if (!verifyManagerVoidPin($pin)) {
            $_SESSION['error'] = managerVoidPinIsConfigured()
                ? 'Invalid manager PIN.'
                : 'Manager void PIN is not set. Set it under Settings.';
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }

        $payStmt = $db->prepare("SELECT * FROM laybye_payments WHERE laybye_id = ? AND payment_kind IN ('deposit','installment')");
        $payStmt->execute([$laybyeId]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalPaid = 0;
        $orderIds = [];
        foreach ($payments as $p) {
            $totalPaid += floatval($p['amount']);
            if (!empty($p['order_id'])) {
                $orderIds[] = (int) $p['order_id'];
            }
        }
        $orderIds = array_values(array_unique(array_filter($orderIds)));

        $db->beginTransaction();
        try {
            if (!empty($orderIds)) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $db->prepare("DELETE FROM eft_payments WHERE order_id IN ($ph)")->execute($orderIds);
                $db->prepare("DELETE FROM mixed_payments WHERE order_id IN ($ph)")->execute($orderIds);
                $db->prepare("DELETE FROM order_items WHERE order_id IN ($ph)")->execute($orderIds);
                $db->prepare("DELETE FROM orders WHERE id IN ($ph)")->execute($orderIds);
            }

            $itemsStmt = $db->prepare('SELECT * FROM laybye_items WHERE laybye_id = ?');
            $itemsStmt->execute([$laybyeId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            $currentDate = date('Y-m-d');
            $stmtGetProductInfo = $db->prepare('SELECT category FROM products WHERE name = ?');
            $resolveProductStmt = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');

            foreach ($items as $item) {
                $name = $item['product_name'];
                $qty = (int) $item['quantity'];
                $stmtGetProductInfo->execute([$name]);
                $info = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
                $cat = $info ? ($info['category'] ?? '') : '';
                $isFood = strtolower(trim($cat)) === 'food';
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
                    ")->execute([$currentDate, $name]);
                    $db->prepare("
                        UPDATE daily_stock_summary 
                        SET sold_quantity = CASE WHEN sold_quantity - ? < 0 THEN 0 ELSE sold_quantity - ? END
                        WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)
                    ")->execute([$qty, $qty, $currentDate, $name]);
                }
            }

            if ($totalPaid > 0.01) {
                $db->prepare("
                    INSERT INTO laybye_payments (laybye_id, amount, payment_method, transaction_ref, wallet_provider, cashier_id, order_id, payment_kind)
                    VALUES (?, ?, 'cash', 'cancel', '', ?, NULL, 'refund')
                ")->execute([$laybyeId, $totalPaid, $cashierUsername]);
            }

            $db->prepare("UPDATE laybye_accounts SET status = 'cancelled', balance_due = 0, closed_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $laybyeId]);

            $db->commit();
            $_SESSION['success'] = 'Lay-bye cancelled; stock restored and refund recorded.';
            header('Location: laybye.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Cancel failed: ' . $e->getMessage();
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }
    }

    if (isset($_POST['payment_amount'], $_POST['laybye_id'])) {
        $laybyeId = (int) $_POST['laybye_id'];
        $amount = floatval($_POST['payment_amount']);
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $transactionRef = $_POST['transaction_ref'] ?? '';
        $walletProvider = $_POST['wallet_provider'] ?? '';
        $cashAmount = floatval($_POST['cash_amount'] ?? 0);
        $eftAmount = floatval($_POST['eft_amount'] ?? 0);
        $cashTenderedInput = floatval($_POST['cash_tendered'] ?? 0);

        $accStmt = $db->prepare('SELECT * FROM laybye_accounts WHERE id = ?');
        $accStmt->execute([$laybyeId]);
        $acc = $accStmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || $acc['status'] !== 'active') {
            $_SESSION['error'] = 'Invalid lay-bye';
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }
        $roleC = strtolower($_SESSION['role'] ?? '');
        $canMutate = in_array($roleC, ['admin', 'manager'], true)
            || (string) ($acc['cashier_id'] ?? '') === (string) ($_SESSION['user_id'] ?? '')
            || (string) ($acc['cashier_id'] ?? '') === (string) ($_SESSION['username'] ?? '');
        if (!$canMutate) {
            $_SESSION['error'] = 'Not allowed';
            header('Location: laybye.php');
            exit;
        }
        if ($amount <= 0) {
            $_SESSION['error'] = 'Amount must be greater than zero';
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }

        $balanceDue = floatval($acc['balance_due']);
        $apply = min($amount, $balanceDue);
        if ($apply <= 0) {
            $_SESSION['error'] = 'Nothing due on this lay-bye';
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }

        $isMixedPayment = $paymentMethod === 'mixed';
        if ($isMixedPayment && abs(($cashAmount + $eftAmount) - $apply) > 0.02) {
            $_SESSION['error'] = 'Cash + EFT must equal payment amount';
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }

        $cashReceivedForOrder = 0;
        if ($paymentMethod === 'cash') {
            $cashReceivedForOrder = $cashTenderedInput > 0 ? $cashTenderedInput : $apply;
            if ($cashReceivedForOrder + 0.001 < $apply) {
                $_SESSION['error'] = 'Cash tendered must cover payment';
                header('Location: view-laybye.php?id=' . $laybyeId);
                exit;
            }
        } elseif ($isMixedPayment) {
            $cashReceivedForOrder = $cashAmount;
        }

        $db->beginTransaction();
        try {
            $orderId = laybyeCreatePaymentOrder(
                $db,
                $apply,
                $cashReceivedForOrder,
                $paymentMethod,
                $transactionRef,
                $walletProvider,
                $cashAmount,
                $eftAmount,
                $cashierUsername
            );

            $db->prepare("
                INSERT INTO laybye_payments (laybye_id, amount, payment_method, transaction_ref, wallet_provider, cashier_id, order_id, payment_kind)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'installment')
            ")->execute([$laybyeId, $apply, $paymentMethod, $transactionRef, $walletProvider, $cashierUsername, $orderId]);

            $newBal = round($balanceDue - $apply, 2);
            $nextDue = $acc['next_due_date'] ? laybyeAdvanceDueDate($acc['plan_frequency'], $acc['next_due_date']) : null;
            $status = $newBal <= 0.01 ? 'completed' : 'active';
            $closedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;

            $db->prepare('UPDATE laybye_accounts SET balance_due = ?, next_due_date = ?, status = ?, closed_at = COALESCE(?, closed_at) WHERE id = ?')
                ->execute([max(0, $newBal), $nextDue, $status, $closedAt, $laybyeId]);

            $db->commit();
            $_SESSION['success'] = 'Payment recorded.';
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Payment failed: ' . $e->getMessage();
            header('Location: view-laybye.php?id=' . $laybyeId);
            exit;
        }
    }
}

$laybyeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($laybyeId <= 0) {
    header('Location: laybye.php');
    exit;
}

$accStmt = $db->prepare("
    SELECT l.*, c.name AS creditor_name, c.phone AS creditor_phone
    FROM laybye_accounts l
    LEFT JOIN creditors c ON c.id = l.creditor_id
    WHERE l.id = ?
");
$accStmt->execute([$laybyeId]);
$laybye = $accStmt->fetch(PDO::FETCH_ASSOC);
if (!$laybye) {
    header('Location: laybye.php');
    exit;
}

$role = strtolower($_SESSION['role'] ?? '');
$curUser = $_SESSION['username'] ?? '';
$curId = (string) ($_SESSION['user_id'] ?? '');
$canViewLaybye = in_array($role, ['admin', 'manager'], true)
    || (string) ($laybye['cashier_id'] ?? '') === $curId
    || (string) ($laybye['cashier_id'] ?? '') === $curUser;
if (!$canViewLaybye) {
    header('Location: laybye.php');
    exit;
}

$items = $db->prepare('
    SELECT li.*,
           (SELECT image_url FROM products WHERE name = li.product_name LIMIT 1) AS product_image
    FROM laybye_items li
    WHERE li.laybye_id = ?
    ORDER BY li.added_at DESC
');
$items->execute([$laybyeId]);
$laybyeItems = $items->fetchAll(PDO::FETCH_ASSOC);

$payLog = $db->prepare('SELECT * FROM laybye_payments WHERE laybye_id = ? ORDER BY payment_date DESC');
$payLog->execute([$laybyeId]);
$payments = $payLog->fetchAll(PDO::FETCH_ASSOC);

try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN hide_available_quantity BOOLEAN NOT NULL DEFAULT 0");
} catch (PDOException $e) {
}
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN skip_stock_checks BOOLEAN NOT NULL DEFAULT 0");
} catch (PDOException $e) {
}
$laybyeProductSettings = $db->query("SELECT hide_available_quantity, skip_stock_checks FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$hide_available_quantity = (int) ($laybyeProductSettings['hide_available_quantity'] ?? 0);
$skip_stock_checks = (int) ($laybyeProductSettings['skip_stock_checks'] ?? 0);

$products = $db->query("SELECT id, name, price, quantity, category FROM products WHERE name != 'Lay-bye Payment' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$recipeProductNames = [];
try {
    $recipeStmt = $db->query("
        SELECT DISTINCT p.name
        FROM products p
        INNER JOIN product_recipes pr ON pr.product_id = p.id
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
    ");
    foreach ($recipeStmt->fetchAll(PDO::FETCH_ASSOC) as $rrow) {
        $recipeProductNames[$rrow['name']] = true;
    }
} catch (PDOException $e) {
}

$itemsTotal = 0;
foreach ($laybyeItems as $li) {
    $itemsTotal += floatval($li['quantity']) * floatval($li['price']);
}

$defaultPay = min(floatval($laybye['balance_due']), floatval($laybye['installment_amount']) > 0 ? floatval($laybye['installment_amount']) : floatval($laybye['balance_due']));
if ($defaultPay <= 0) {
    $defaultPay = floatval($laybye['balance_due']);
}

$dbInfoReceipt = new PDO('sqlite:' . __DIR__ . '/info.db');
$businessInfoLaybye = $dbInfoReceipt->query('SELECT * FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$businessInfoLaybye) {
    $businessInfoLaybye = [
        'name' => 'POS SOLUTION',
        'location' => '',
        'phone' => '',
        'footer_text' => 'Thank you!',
    ];
}
$laybyeBalanceReceipt = laybyeBuildBalanceReceiptPayload($laybye, $laybyeItems, $payments, $businessInfoLaybye, $cashierUsername);
$laybyePaymentReceipts = laybyeBuildPaymentReceiptPayloads($db, $laybye, $payments, $businessInfoLaybye);
$laybyeReceiptJsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lay-bye <?= htmlspecialchars($laybye['reference'] ?? '') ?></title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="sweetalert2@11.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <?php include 'sidebar.php'; ?>
    <div class="mobile-overlay lg:hidden fixed inset-0 bg-black/50 z-[80] hidden" id="mobileOverlay" onclick="closeSidebar()"></div>
    <div class="content flex-1 lg:ml-64 p-4 lg:p-6 mx-auto">
        <div class="lg:hidden flex items-center gap-2 mb-4">
            <a href="laybye" class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors flex-shrink-0">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back
            </a>
            <button type="button" class="hamburger lg:hidden p-2 flex-shrink-0" onclick="toggleSidebar()" aria-label="Open menu"><span></span><span></span><span></span></button>
            <span class="text-lg font-semibold flex-1 min-w-0 truncate text-center"><?= htmlspecialchars($laybye['reference'] ?? 'Lay-bye') ?></span>
        </div>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="mb-4 p-3 rounded-lg bg-teal-50 text-teal-800 text-sm"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
            <div class="min-w-0 flex-1">
                <a href="laybye" class="hidden lg:inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors mb-3">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back
                </a>
                <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($laybye['reference'] ?? '') ?></h1>
                <p class="text-gray-700"><?= htmlspecialchars($laybye['creditor_name'] ?? '') ?> <?= $laybye['creditor_phone'] ? ' · ' . htmlspecialchars($laybye['creditor_phone']) : '' ?></p>
                <p class="text-sm text-gray-500 mt-1">Opened <?= htmlspecialchars($laybye['opened_at'] ?? '') ?> · <?= htmlspecialchars($laybye['plan_frequency']) ?>, <?= (int) laybyeEffectivePlanPeriod($laybye) ?> payments · N$<?= number_format((float) $laybye['installment_amount'], 2) ?>/payment · Next due: <?= $laybye['next_due_date'] ? htmlspecialchars($laybye['next_due_date']) : '—' ?></p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-600">Balance due</p>
                <p class="text-2xl font-bold text-orange-600">N$<?= number_format((float) $laybye['balance_due'], 2) ?></p>
                <button type="button" id="btnPrintLaybyeStatement" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-teal-800 bg-teal-100 hover:bg-teal-200 border border-teal-200 transition-colors">
                    <i class="fas fa-print" aria-hidden="true"></i> Print statement
                </button>
                <span class="inline-flex mt-2 px-2 py-0.5 rounded-full text-xs font-medium <?= $laybye['status'] === 'active' ? 'bg-teal-100 text-teal-800' : ($laybye['status'] === 'completed' ? 'bg-gray-100 text-gray-700' : 'bg-red-100 text-red-800') ?>"><?= htmlspecialchars($laybye['status']) ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                <h2 class="font-semibold text-gray-900 mb-3">Items</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-500 border-b"><th class="pb-2">Product</th><th class="pb-2 text-right">Qty</th><th class="pb-2 text-right">Unit</th><th class="pb-2 text-right">Line</th><?php if ($laybye['status'] === 'active'): ?><th class="pb-2 text-right w-24">Remove</th><?php endif; ?></tr></thead>
                        <tbody>
                            <?php foreach ($laybyeItems as $li): ?>
                                <tr class="border-b border-gray-100">
                                    <td class="py-2">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="relative w-10 h-10 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0">
                                                <img src="products/<?= htmlspecialchars((string) ($li['product_image'] ?? ''), ENT_QUOTES) ?>"
                                                     alt="<?= htmlspecialchars($li['product_name'], ENT_QUOTES) ?>"
                                                     class="w-full h-full object-cover"
                                                     onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                <span class="w-full h-full items-center justify-center hidden" aria-hidden="true"><i class="fas fa-cube text-gray-400 text-lg"></i></span>
                                            </div>
                                            <span class="font-medium text-gray-900 min-w-0"><?= htmlspecialchars($li['product_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-2 text-right"><?= (int) $li['quantity'] ?></td>
                                    <td class="py-2 text-right">N$<?= number_format((float) $li['price'], 2) ?></td>
                                    <td class="py-2 text-right font-medium">N$<?= number_format((float) $li['quantity'] * (float) $li['price'], 2) ?></td>
                                    <?php if ($laybye['status'] === 'active'): ?>
                                    <td class="py-2 text-right">
                                        <button type="button" class="btn-remove-laybye-item text-red-600 hover:text-red-800 text-xs font-medium" data-item-id="<?= (int) $li['id'] ?>" data-product-name="<?= htmlspecialchars($li['product_name'], ENT_QUOTES) ?>">Remove</button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td colspan="3" class="pt-2 text-right text-gray-600">Goods total</td><td class="pt-2 text-right font-bold">N$<?= number_format($itemsTotal, 2) ?></td><?php if ($laybye['status'] === 'active'): ?><td class="pt-2"></td><?php endif; ?></tr></tfoot>
                    </table>
                </div>

                <?php if ($laybye['status'] === 'active'): ?>
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <h3 class="text-sm font-medium text-gray-800 mb-2">Add product</h3>
                    <?php if (!$hide_available_quantity): ?>
                    <p class="text-xs text-gray-500 mb-2">Stock levels follow Settings (show quantities). Recipe items validate ingredients on save.</p>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-2 items-end">
                        <select id="addProductId" class="border border-gray-300 rounded-lg px-3 py-2 text-sm flex-1 min-w-[140px]">
                            <?php foreach ($products as $pr):
                                $isFood = strtolower(trim((string) ($pr['category'] ?? ''))) === 'food';
                                $hasRecipe = isset($recipeProductNames[$pr['name']]);
                                $q = (float) $pr['quantity'];
                                $stockDisp = (abs($q - round($q)) < 0.00001) ? (string) (int) round($q) : number_format($q, 2);
                                $label = htmlspecialchars($pr['name']) . ' (N$' . number_format((float) $pr['price'], 2) . ')';
                                if (!$hide_available_quantity) {
                                    $label .= ' — Stock: ' . $stockDisp;
                                    if ($hasRecipe) {
                                        $label .= ' (recipe)';
                                    }
                                }
                                ?>
                                <option value="<?= (int) $pr['id'] ?>"
                                    data-name="<?= htmlspecialchars($pr['name'], ENT_QUOTES) ?>"
                                    data-price="<?= htmlspecialchars((string) $pr['price'], ENT_QUOTES) ?>"
                                    data-stock="<?= htmlspecialchars((string) $pr['quantity'], ENT_QUOTES) ?>"
                                    data-is-food="<?= $isFood ? '1' : '0' ?>"
                                    data-has-recipe="<?= $hasRecipe ? '1' : '0' ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="addQty" min="1" value="1" class="border border-gray-300 rounded-lg px-3 py-2 w-20 text-sm">
                        <button type="button" id="btnAddLaybyeItem" class="bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-700">Add</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="space-y-6">
                <?php if ($laybye['status'] === 'active' && floatval($laybye['balance_due']) > 0.01): ?>
                <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                    <h2 class="font-semibold text-gray-900 mb-3">Make payment</h2>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="laybye_id" value="<?= (int) $laybye['id'] ?>">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Amount (N$)</label>
                            <input type="number" name="payment_amount" step="0.01" min="0.01" value="<?= htmlspecialchars(number_format($defaultPay, 2, '.', '')) ?>" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                            <p class="text-xs text-gray-500 mt-1">Applied up to balance due (N$<?= number_format((float) $laybye['balance_due'], 2) ?>).</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Method</label>
                            <div class="flex gap-2 flex-wrap">
                                <label class="inline-flex items-center gap-1 text-sm"><input type="radio" name="payment_method" value="cash" checked> Cash</label>
                                <label class="inline-flex items-center gap-1 text-sm"><input type="radio" name="payment_method" value="eft"> EFT</label>
                                <label class="inline-flex items-center gap-1 text-sm"><input type="radio" name="payment_method" value="mixed"> Mixed</label>
                            </div>
                        </div>
                        <div id="vbCashWrap" class="space-y-1">
                            <label class="text-sm text-gray-600">Cash tendered</label>
                            <input type="number" name="cash_tendered" step="0.01" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="Optional — defaults to amount">
                        </div>
                        <div id="vbMixedWrap" class="hidden grid grid-cols-2 gap-2">
                            <div><label class="text-xs text-gray-600">Cash</label><input type="number" name="cash_amount" step="0.01" min="0" value="0" class="w-full border rounded-lg px-2 py-1"></div>
                            <div><label class="text-xs text-gray-600">EFT</label><input type="number" name="eft_amount" step="0.01" min="0" value="0" class="w-full border rounded-lg px-2 py-1"></div>
                        </div>
                        <div id="vbEftWrap" class="hidden space-y-2">
                            <input type="text" name="transaction_ref" placeholder="Reference (optional)" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                            <select name="wallet_provider" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <option value="Credit Card">Credit Card</option>
                                <option value="E-wallet">E-wallet</option>
                                <option value="Easy Wallet">Easy Wallet</option>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 rounded-lg">Record payment</button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                    <h2 class="font-semibold text-gray-900 mb-3">Payment history</h2>
                    <ul class="space-y-2 text-sm">
                        <?php foreach ($payments as $p): ?>
                            <li class="flex justify-between items-center gap-2 border-b border-gray-100 pb-2">
                                <span class="text-gray-700 min-w-0"><?= htmlspecialchars($p['payment_date'] ?? '') ?> · <?= htmlspecialchars($p['payment_kind']) ?> · <?= htmlspecialchars($p['payment_method']) ?></span>
                                <span class="flex items-center gap-2 flex-shrink-0">
                                    <span class="font-medium">N$<?= number_format((float) $p['amount'], 2) ?></span>
                                    <?php if (laybyePaymentRowIsPrintable($p)): ?>
                                        <button type="button" class="btn-print-laybye-payment text-teal-700 hover:text-teal-900 text-xs font-medium whitespace-nowrap" data-payment-id="<?= (int) $p['id'] ?>">Print</button>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($payments)): ?><li class="text-gray-500">No payments</li><?php endif; ?>
                    </ul>
                </div>

         
            </div>
        </div>
    </div>
    <script src="receipt.php?js=true"></script>
    <script>
        const LAYBYE_BALANCE_RECEIPT = <?= json_encode($laybyeBalanceReceipt, $laybyeReceiptJsonFlags) ?>;
        const LAYBYE_PAYMENT_RECEIPTS = <?= json_encode($laybyePaymentReceipts, $laybyeReceiptJsonFlags) ?>;

        function printLaybyeStatement() {
            if (typeof sendToPrinter === 'undefined') {
                Swal.fire('Printing unavailable', 'Receipt printer script did not load. Refresh the page.', 'error');
                return;
            }
            sendToPrinter(LAYBYE_BALANCE_RECEIPT).then(function(printData) {
                if (printData && printData.success) {
                    Swal.fire({ icon: 'success', title: 'Statement sent', text: 'Lay-bye balance receipt sent to printer.', timer: 2500, showConfirmButton: false });
                } else {
                    Swal.fire('Printing failed', (printData && printData.message) || 'Could not print.', 'error');
                }
            }).catch(function(err) {
                Swal.fire('Printing failed', (err && err.message) || 'Request error', 'error');
            });
        }

        function printLaybyePaymentReceipt(paymentId) {
            if (typeof sendToPrinter === 'undefined') {
                Swal.fire('Printing unavailable', 'Receipt printer script did not load. Refresh the page.', 'error');
                return;
            }
            var key = String(paymentId);
            var orderData = LAYBYE_PAYMENT_RECEIPTS[key];
            if (!orderData) {
                Swal.fire('Error', 'No receipt data for this payment.', 'error');
                return;
            }
            var receiptData = {
                order_id: orderData.order_id,
                items: orderData.items,
                cashier_username: orderData.cashier_username,
                total: orderData.total,
                tips: orderData.tips || 0,
                cash_received: orderData.cash_received || 0,
                payment_method: orderData.payment_method || 'cash',
                transaction_ref: orderData.transaction_ref || '',
                wallet_provider: orderData.wallet_provider || '',
                creditor_name: orderData.creditor_name || '',
                print_only: true,
                is_payment_receipt: true,
                vat_inclusive: orderData.vat_inclusive || 'exclusive',
                vat_rate: orderData.vat_rate || 15.0
            };
            if (orderData.payment_method === 'mixed') {
                receiptData.cash_amount = orderData.cash_amount || 0;
                receiptData.eft_amount = orderData.eft_amount || 0;
                receiptData.eft_transaction_ref = orderData.eft_transaction_ref || '';
                receiptData.eft_wallet_provider = orderData.eft_wallet_provider || '';
            }
            sendToPrinter(receiptData).then(function(printData) {
                if (printData && printData.success) {
                    if ((orderData.payment_method || '') === 'cash') {
                        var total = parseFloat(orderData.total) || 0;
                        var tendered = parseFloat(orderData.cash_received) || 0;
                        var change = Math.max(0, tendered - total);
                        var changeBlock = change < 0.005
                            ? '<p class="text-lg text-gray-700 mt-4">Exact amount — <span class="font-semibold">no change</span></p>'
                            : '<p class="text-sm text-gray-600 mt-3 mb-1">Change to give the customer</p><p class="text-4xl font-bold text-teal-600 tracking-tight">N$ ' + change.toFixed(2) + '</p>';
                        Swal.fire({ icon: 'success', title: 'Payment receipt', html: '<p class="text-sm text-gray-600">Receipt sent to printer.</p>' + changeBlock, confirmButtonColor: '#0d9488' });
                    } else {
                        Swal.fire({ icon: 'success', title: 'Receipt sent', text: 'Payment receipt sent to printer.', timer: 2500, showConfirmButton: false });
                    }
                } else {
                    Swal.fire('Printing failed', (printData && printData.message) || 'Could not print.', 'error');
                }
            }).catch(function(err) {
                Swal.fire('Printing failed', (err && err.message) || 'Request error', 'error');
            });
        }

        document.getElementById('btnPrintLaybyeStatement')?.addEventListener('click', printLaybyeStatement);
        document.querySelectorAll('.btn-print-laybye-payment').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = parseInt(btn.getAttribute('data-payment-id'), 10);
                if (id) printLaybyePaymentReceipt(id);
            });
        });

        function toggleSidebar() {
            document.getElementById('sidebar')?.classList.toggle('open');
            document.querySelector('.hamburger')?.classList.toggle('open');
            document.getElementById('mobileOverlay')?.classList.toggle('hidden');
        }
        (function() {
            const methods = document.querySelectorAll('input[name="payment_method"]');
            const cashWrap = document.getElementById('vbCashWrap');
            const eftWrap = document.getElementById('vbEftWrap');
            const mixedWrap = document.getElementById('vbMixedWrap');
            function sync() {
                const m = document.querySelector('input[name="payment_method"]:checked')?.value;
                if (eftWrap) eftWrap.classList.toggle('hidden', m !== 'eft' && m !== 'mixed');
                if (mixedWrap) mixedWrap.classList.toggle('hidden', m !== 'mixed');
                if (cashWrap) cashWrap.classList.toggle('hidden', m === 'eft' || m === 'mixed');
            }
            methods.forEach(r => r.addEventListener('change', sync));
            sync();
        })();
        const skipStockChecksLaybye = <?= $skip_stock_checks ? 'true' : 'false' ?>;
        document.getElementById('btnAddLaybyeItem')?.addEventListener('click', function() {
            const sel = document.getElementById('addProductId');
            const opt = sel.options[sel.selectedIndex];
            const name = opt.getAttribute('data-name');
            const unit = parseFloat(opt.getAttribute('data-price')) || 0;
            const qty = parseInt(document.getElementById('addQty').value, 10) || 1;
            if (!name) return;
            if (!skipStockChecksLaybye) {
                const isFood = opt.getAttribute('data-is-food') === '1';
                const hasRecipe = opt.getAttribute('data-has-recipe') === '1';
                if (!isFood && !hasRecipe) {
                    const avail = parseFloat(opt.getAttribute('data-stock') || '0');
                    if (qty > avail) {
                        Swal.fire('Insufficient stock', 'Only ' + avail + ' available for ' + name + '.', 'warning');
                        return;
                    }
                }
            }
            const payload = {
                laybye_id: <?= (int) $laybye['id'] ?>,
                items: [{ name: name, quantity: qty, price: unit * qty }]
            };
            fetch('process_laybye_add_items.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    Swal.fire({ icon: 'success', title: 'Added', timer: 1000, showConfirmButton: false }).then(() => location.reload());
                } else {
                    Swal.fire('Error', d.message || 'Failed', 'error');
                }
            }).catch(() => Swal.fire('Error', 'Request failed', 'error'));
        });
        document.getElementById('btnCancelLaybye')?.addEventListener('click', function() {
            Swal.fire({
                title: 'Cancel this lay-bye?',
                text: 'Stock will be restored and a refund line recorded on this lay-bye. No cash-out entry is created. Enter manager void PIN.',
                icon: 'warning',
                input: 'password',
                inputLabel: 'Manager void PIN',
                inputAttributes: { autocapitalize: 'off', autocomplete: 'off' },
                showCancelButton: true,
                confirmButtonText: 'Cancel lay-bye',
                confirmButtonColor: '#dc2626',
            }).then(function(res) {
                if (!res.isConfirmed) return;
                document.getElementById('cancelLaybyeManagerPin').value = res.value || '';
                document.getElementById('formCancelLaybye').submit();
            });
        });
        document.querySelectorAll('.btn-remove-laybye-item').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var itemId = parseInt(btn.getAttribute('data-item-id'), 10);
                var label = btn.getAttribute('data-product-name') || 'this line';
                Swal.fire({
                    title: 'Remove item?',
                    text: 'Remove ' + label + ' from this lay-bye? Enter manager void PIN.',
                    icon: 'warning',
                    input: 'password',
                    inputLabel: 'Manager void PIN',
                    inputAttributes: { autocapitalize: 'off', autocomplete: 'off' },
                    showCancelButton: true,
                    confirmButtonText: 'Remove',
                    confirmButtonColor: '#dc2626',
                }).then(function(res) {
                    if (!res.isConfirmed) return;
                    fetch('process_laybye_remove_item.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            laybye_id: <?= (int) $laybye['id'] ?>,
                            item_id: itemId,
                            manager_pin: res.value || ''
                        })
                    }).then(function(r) { return r.json(); }).then(function(d) {
                        if (d.success) {
                            Swal.fire({ icon: 'success', title: 'Removed', timer: 1000, showConfirmButton: false }).then(function() { location.reload(); });
                        } else {
                            Swal.fire('Error', d.message || 'Failed', 'error');
                        }
                    }).catch(function() { Swal.fire('Error', 'Request failed', 'error'); });
                });
            });
        });
    </script>
</body>
</html>
