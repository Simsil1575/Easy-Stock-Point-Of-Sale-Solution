<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Harare');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/includes/barcode_labels_lib.php';

blRequireAdminOrManager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

try {
    $db = blGetDb();
    blEnsureSettings($db);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $ids = isset($payload['product_ids']) && is_array($payload['product_ids'])
        ? $payload['product_ids']
        : [];
    $copies = max(1, min(99, (int) ($payload['copies'] ?? 1)));
    $settings = blGetSettings($db);

    if (!empty($payload['settings']) && is_array($payload['settings'])) {
        blSaveSettings($db, array_merge($settings, $payload['settings']));
        $settings = blGetSettings($db);
    }

    $printOptions = blMergePrintOptions($settings, is_array($payload['label_options'] ?? null) ? $payload['label_options'] : []);
    $products = blGetProductsByIds($db, $ids);
    $printable = array_values(array_filter($products, fn($p) => blProductPrintable($p, $printOptions)));
    $skipped = count($products) - count($printable);

    $zpl = blBuildBulkZpl($printable, $copies, $printOptions);

    blSendZplToNetwork(
        (string) $settings['label_printer_ip'],
        (int) $settings['label_printer_port'],
        $zpl
    );

    echo json_encode([
        'success' => true,
        'printed_count' => count($printable) * $copies,
        'skipped' => $skipped,
        'message' => 'Labels sent to network printer.',
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
