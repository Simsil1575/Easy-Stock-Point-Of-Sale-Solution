<?php

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'], $_SESSION['role']) || !in_array((string) $_SESSION['role'], ['admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/receiving_list_lib.php';

try {
    $db = new PDO('sqlite:' . __DIR__ . '/../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $result = receivingListFetchPage($db, [
        'page' => $_GET['page'] ?? 1,
        'per_page' => $_GET['per_page'] ?? 6,
        'search' => $_GET['search'] ?? '',
        'category' => $_GET['category'] ?? '',
        'sort_col' => $_GET['sort_col'] ?? 'name',
        'sort_dir' => $_GET['sort_dir'] ?? 'ASC',
        'view_all' => !empty($_GET['view_all']),
    ]);

    echo json_encode([
        'success' => true,
        'html' => $result['html'],
        'items' => $result['items'],
        'total' => $result['total'],
        'page' => $result['page'],
        'per_page' => $result['per_page'],
        'total_pages' => $result['total_pages'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
