<?php
session_start();
header('Content-Type: application/json');

date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$pdo = new PDO('sqlite:active.db');
if ($pdo->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() == 0) {
    echo json_encode(['success' => false, 'message' => 'Not activated']);
    exit;
}

require_once __DIR__ . '/manager_pin_helper.php';
require_once __DIR__ . '/ensure_laybye_schema.php';
require_once __DIR__ . '/laybye_order_helper.php';

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureLaybyeSchema($db);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['laybye_id'])) {
        throw new Exception('Missing laybye_id');
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
    $cashierUsername = $_SESSION['username'] ?? 'Unknown';

    $accStmt = $db->prepare('SELECT * FROM laybye_accounts WHERE id = ?');
    $accStmt->execute([$laybyeId]);
    $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        throw new Exception('Lay-bye not found');
    }

    $roleC = strtolower($_SESSION['role'] ?? '');
    $canMutate = in_array($roleC, ['admin', 'manager'], true)
        || (string) ($acc['cashier_id'] ?? '') === (string) ($_SESSION['user_id'] ?? '')
        || (string) ($acc['cashier_id'] ?? '') === (string) ($_SESSION['username'] ?? '');
    if (!$canMutate) {
        throw new Exception('Not allowed');
    }

    $db->beginTransaction();
    try {
        laybyePermanentlyDeleteAccount($db, $laybyeId, $cashierUsername);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
