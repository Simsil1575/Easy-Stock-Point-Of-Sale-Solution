<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (strtolower((string) $_SESSION['role']) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

require_once __DIR__ . '/../report_amount_override_helper.php';

try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        $db->exec("ALTER TABLE product_settings ADD COLUMN admin_edit_report_amounts BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
    }

    $editAmtRow = $db->query("SELECT admin_edit_report_amounts FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ((int) ($editAmtRow['admin_edit_report_amounts'] ?? 0) !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Report amount editing is disabled in settings']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = (string) ($payload['action'] ?? '');
$userId = (int) $_SESSION['user_id'];
$username = (string) $_SESSION['username'];

try {
    if ($action === 'save') {
        $result = raoSaveOverride($db, $payload, $userId, $username);
        echo json_encode(['success' => true, 'override' => $result]);
        exit;
    }

    if ($action === 'revert') {
        $result = raoRevertOverride($db, $payload, $userId, $username);
        echo json_encode(['success' => true, 'override' => $result]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to process override']);
}
