<?php
session_start();
require_once 'cashier_helper.php';

date_default_timezone_set('Africa/Harare');

if (!validateCashierSession()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['amount']) || !isset($input['description'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

$amount = floatval($input['amount']);
$description = trim($input['description']);
$expenseDate = isset($input['date']) ? preg_replace('/[^0-9\-]/', '', $input['date']) : '';

if ($amount <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit();
}

if ($description === '') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Description is required']);
    exit();
}

if ($expenseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
    $expenseDate = date('Y-m-d');
}

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $cashier = getCashierInfo();
    $createdAt = $expenseDate . ' 10:00:00';

    $stmt = $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['cash-out', $amount, $description, $cashier['username'], $createdAt]);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Expense recorded successfully',
        'amount' => $amount,
        'description' => $description
    ]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
