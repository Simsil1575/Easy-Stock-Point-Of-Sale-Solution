<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Harare');

if (!isset($roleFolder) || !in_array($roleFolder, ['admin', 'manager'], true)) {
    $roleFolder = 'admin';
}

require_once __DIR__ . '/../barcode_labels_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../');
    exit;
}

blRequireAdminOrManager();

try {
    $activationDb = new PDO('sqlite:' . __DIR__ . '/../../active.db');
    if ((int) $activationDb->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() === 0) {
        header('Location: settings');
        exit;
    }
} catch (Throwable $e) {
    // non-fatal
}

$db = blGetDb();
blEnsureSettings($db);

$blBase = '../';
$backHref = $roleFolder . '-center';
$ajaxUrl = $blBase . 'barcode_labels_ajax.php';
$printUrl = $blBase . 'print_barcode_labels.php';
$pdfUrl = $blBase . 'generate_barcode_labels_pdf.php';

$settings = blGetSettings($db);
$categories = blListCategories($db);

$search = trim((string) ($_GET['search'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$list = blListProducts($db, [
    'search' => $search,
    'category' => $category,
    'status' => $status,
    'limit' => $limit,
    'offset' => $offset,
]);

$pageTitle = 'Barcode Labels';
$mobileTitle = 'Barcode Labels';

$flash = (string) ($_SESSION['bl_flash'] ?? '');
$flashErr = (string) ($_SESSION['bl_flash_err'] ?? '');
unset($_SESSION['bl_flash'], $_SESSION['bl_flash_err']);

$queryParams = array_filter([
    'search' => $search,
    'category' => $category,
    'status' => $status,
    'limit' => $limit !== 200 ? (string) $limit : '',
], static fn($v) => $v !== '' && $v !== null);

$listQuery = static function (array $extra = []) use ($queryParams, $offset): string {
    $params = array_filter(array_merge($queryParams, $extra), static fn($v) => $v !== '' && $v !== null);
    if (!isset($params['offset']) && $offset > 0) {
        $params['offset'] = (string) $offset;
    }
    return $params === [] ? '' : '?' . http_build_query($params);
};

$hasMore = ($offset + count($list['products'])) < $list['total'];
$nextOffset = $offset + $limit;
