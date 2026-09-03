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

try {
    $db = blGetDb();
    blEnsureSettings($db);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$jsonBody = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $jsonBody = $decoded;
        }
    }
}

$action = (string) ($_REQUEST['action'] ?? ($jsonBody['action'] ?? ''));

try {
    switch ($action) {
        case 'save_settings':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('POST required.');
            }
            blSaveSettings($db, $jsonBody !== [] ? $jsonBody : $_POST);
            echo json_encode(['success' => true, 'settings' => blGetSettings($db)]);
            break;

        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('POST required.');
            }
            $payload = $jsonBody !== [] ? $jsonBody : $_POST;
            $productId = (int) ($payload['product_id'] ?? 0);
            $barcode = isset($payload['barcode']) ? (string) $payload['barcode'] : null;
            $value = blCreateBarcode($db, $productId, $barcode);
            echo json_encode(['success' => true, 'barcode' => $value, 'product' => blGetProduct($db, $productId)]);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('POST required.');
            }
            $payload = $jsonBody !== [] ? $jsonBody : $_POST;
            $productId = (int) ($payload['product_id'] ?? 0);
            $barcode = trim((string) ($payload['barcode'] ?? ''));
            if ($barcode === '') {
                throw new RuntimeException('Barcode cannot be empty. Use delete to clear.');
            }
            blSetBarcode($db, $productId, $barcode);
            echo json_encode(['success' => true, 'product' => blGetProduct($db, $productId)]);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('POST required.');
            }
            $payload = $jsonBody !== [] ? $jsonBody : $_POST;
            $productId = (int) ($payload['product_id'] ?? 0);
            blSetBarcode($db, $productId, null);
            echo json_encode(['success' => true, 'product' => blGetProduct($db, $productId)]);
            break;

        case 'generate_missing':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('POST required.');
            }
            $payload = $jsonBody !== [] ? $jsonBody : $_POST;
            $ids = isset($payload['product_ids']) && is_array($payload['product_ids'])
                ? $payload['product_ids']
                : null;
            $count = blGenerateMissingBarcodes($db, $ids);
            echo json_encode(['success' => true, 'generated' => $count]);
            break;

        case 'list':
            $result = blListProducts($db, [
                'search' => $_GET['search'] ?? '',
                'category' => $_GET['category'] ?? '',
                'status' => $_GET['status'] ?? '',
                'limit' => $_GET['limit'] ?? 200,
                'offset' => $_GET['offset'] ?? 0,
            ]);
            echo json_encode(['success' => true] + $result);
            break;

        case 'get_settings':
            echo json_encode(['success' => true, 'settings' => blGetSettings($db)]);
            break;

        case 'get_zpl':
            $ids = $_REQUEST['ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $copies = max(1, min(99, (int) ($_REQUEST['copies'] ?? 1)));
            $settings = blGetSettings($db);
            $printOptions = blMergePrintOptions($settings, $_REQUEST);
            $products = blGetProductsByIds($db, $ids);
            $printable = array_values(array_filter($products, fn($p) => blProductPrintable($p, $printOptions)));
            $skipped = count($products) - count($printable);
            $zpl = blBuildBulkZpl($printable, $copies, $printOptions);
            if ($zpl === '') {
                throw new RuntimeException('No printable labels for the selected products and fields.');
            }
            echo json_encode([
                'success' => true,
                'zpl' => $zpl,
                'printed_count' => count($printable) * $copies,
                'skipped' => $skipped,
            ]);
            break;

        case 'test_label':
            $settings = blGetSettings($db);
            $printOptions = blMergePrintOptions($settings, $_REQUEST);
            $zpl = blBuildLabelZpl(blTestLabelProduct(), $printOptions);
            echo json_encode([
                'success' => true,
                'zpl' => $zpl,
            ]);
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
