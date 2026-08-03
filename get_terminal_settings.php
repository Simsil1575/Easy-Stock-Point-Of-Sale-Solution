<?php
header('Content-Type: application/json');

require_once __DIR__ . '/cashier_helper.php';
require_once __DIR__ . '/terminal_helper.php';

requireApiSession();

date_default_timezone_set('Africa/Harare');

try {
    $mac = normalizeMacAddress($_GET['mac'] ?? null);
    if ($mac === null) {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing MAC address']);
        exit;
    }

    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureTerminalSchema($db);

    $terminal = getTerminalByMac($db, $mac);

    echo json_encode([
        'success' => true,
        'terminal_mac' => $mac,
        'terminal_name' => $terminal['terminal_name'] ?? '',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
