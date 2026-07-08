<?php
session_start();
header('Content-Type: application/json');

date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['creditor_id']) || !isset($input['amount']) || !isset($input['date']) || !isset($input['payment_method'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields: creditor_id, amount, date, payment_method']);
    exit();
}

$creditorId = (int) $input['creditor_id'];
$paymentAmount = (float) $input['amount'];
$paymentDate = preg_replace('/[^0-9\-]/', '', $input['date']);
$paymentMethod = strtolower(trim($input['payment_method'] ?? ''));
$isEft = ($paymentMethod === 'eft');

if ($paymentAmount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit();
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date']);
    exit();
}

$cashierId = $_SESSION['username'] ?? 'Unknown';
// Store payment with selected date at 10:00
$paymentDateTime = $paymentDate . ' 10:00:00';

try {
    $creditor = $db->prepare("SELECT id, name FROM creditors WHERE id = ?");
    $creditor->execute([$creditorId]);
    $creditor = $creditor->fetch(PDO::FETCH_ASSOC);
    if (!$creditor) {
        echo json_encode(['success' => false, 'message' => 'Creditor not found']);
        exit();
    }

    // Record as a single "System Balance" payment: create one credit sale (paid in full) + one payment. No outstanding check.
    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO credit_sales (creditor_id, total_amount, due_date, created_at, paid_amount, payment_status, cashier_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $creditorId,
        $paymentAmount,
        $paymentDate,
        $paymentDateTime,
        $paymentAmount,
        $isEft ? 'eft' : 'paid',
        $cashierId
    ]);
    $saleId = (int) $db->lastInsertId();

    $itemStmt = $db->prepare("INSERT INTO credit_sale_items (sale_id, product_name, quantity, price, buying_price) VALUES (?, 'System Balance', 1, ?, NULL)");
    $itemStmt->execute([$saleId, $paymentAmount]);

    $paymentStmt = $db->prepare("INSERT INTO payments (sale_id, amount, payment_date, cashier_id) VALUES (?, ?, ?, ?)");
    $paymentStmt->execute([$saleId, $paymentAmount, $paymentDateTime, $cashierId]);

    if ($isEft) {
        $eftStmt = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (?, 'N/A', 'N/A', ?, ?, ?)");
        $eftStmt->execute([$saleId, $paymentAmount, $cashierId, $paymentDateTime]);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'creditor_name' => $creditor['name'],
        'amount' => $paymentAmount,
        'date' => $paymentDate
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
