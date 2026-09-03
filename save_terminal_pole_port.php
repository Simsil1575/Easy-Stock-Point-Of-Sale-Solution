<?php
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/cashier_helper.php';
    require_once __DIR__ . '/terminal_helper.php';

    requireApiSession();

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $mac = normalizeMacAddress($data['terminal_mac'] ?? null);
    if ($mac === null) {
        echo json_encode(['success' => false, 'message' => 'This till is not identified yet. Open cashier Home once, then save again.']);
        exit;
    }

    $port = strtoupper(trim((string) ($data['pole_display_port'] ?? 'AUTO')));
    $db = new PDO('sqlite:' . __DIR__ . '/pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    setTerminalPoleDisplayPort($db, $mac, $port);

    echo json_encode([
        'success' => true,
        'terminal_mac' => $mac,
        'pole_display_port' => $port,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()]);
}
