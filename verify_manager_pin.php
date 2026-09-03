<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

require_once __DIR__ . '/manager_pin_helper.php';

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$pin = trim((string) ($payload['manager_pin'] ?? ''));

if (!managerVoidPinIsConfigured()) {
    echo json_encode([
        'success' => false,
        'message' => 'Manager void PIN is not set. Ask a manager to set it under Settings.',
    ]);
    exit;
}

if (verifyManagerVoidPin($pin)) {
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid manager PIN.']);
