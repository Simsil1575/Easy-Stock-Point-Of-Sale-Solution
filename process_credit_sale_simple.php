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
require_once __DIR__ . '/credit_limit_helper.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['creditor_id']) || !isset($input['amount']) || !isset($input['date'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields: creditor_id, amount, date']);
    exit();
}

$creditorId = (int) $input['creditor_id'];
$amount = (float) $input['amount'];
$saleDate = preg_replace('/[^0-9\-]/', '', $input['date']);
$dueDate = isset($input['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($input['due_date']))
    ? trim($input['due_date'])
    : $saleDate;

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit();
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date']);
    exit();
}

try {
    $creditor = $db->prepare("SELECT id, name FROM creditors WHERE id = ? AND active = 1");
    $creditor->execute([$creditorId]);
    $creditor = $creditor->fetch(PDO::FETCH_ASSOC);
    if (!$creditor) {
        echo json_encode(['success' => false, 'message' => 'Invalid or inactive creditor']);
        exit();
    }

    $db->beginTransaction();

    assertCreditSaleWithinLimit($db, $creditorId, $amount);

    // created_at on the selected date at 10:00
    $createdAt = $saleDate . ' 10:00:00';
    $cashierId = $_SESSION['username'] ?? 'Unknown';

    $stmt = $db->prepare("INSERT INTO credit_sales (creditor_id, total_amount, due_date, created_at, cashier_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$creditorId, $amount, $dueDate, $createdAt, $cashierId]);
    $saleId = (int) $db->lastInsertId();

    // Single line item: System Balance (no stock deduction)
    $itemStmt = $db->prepare("INSERT INTO credit_sale_items (sale_id, product_name, quantity, price, buying_price) VALUES (?, ?, 1, ?, NULL)");
    $itemStmt->execute([$saleId, 'System Balance', $amount]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'creditor_name' => $creditor['name'],
        'amount' => $amount,
        'date' => $saleDate
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
