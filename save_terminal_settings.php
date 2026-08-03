<?php
header('Content-Type: application/json');

require_once __DIR__ . '/cashier_helper.php';
require_once __DIR__ . '/terminal_helper.php';

requireApiSession();

date_default_timezone_set('Africa/Harare');

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $mac = normalizeMacAddress($data['terminal_mac'] ?? null);
    if ($mac === null) {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing MAC address']);
        exit;
    }

    $name = trim((string) ($data['terminal_name'] ?? ''));
    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Terminal name is required']);
        exit;
    }

    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureTerminalSchema($db);
    upsertTerminal($db, $mac, $name);

    echo json_encode([
        'success' => true,
        'terminal_mac' => $mac,
        'terminal_name' => $name,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
