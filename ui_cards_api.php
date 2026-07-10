<?php
session_start();
date_default_timezone_set('Africa/Harare');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not authenticated.']);
    exit;
}

$role = strtolower((string) $_SESSION['role']);
if (!in_array($role, ['admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not allowed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/ui_cards_helper.php';

$action = (string) ($_POST['action'] ?? '');
$scope = trim((string) ($_POST['scope'] ?? ''));
$cardIds = array_values(array_filter(array_map('strval', (array) ($_POST['card_ids'] ?? []))));

$allowedScopes = ['admin_menu', 'manager_menu', 'admin_reports', 'manager_reports'];
if (!in_array($scope, $allowedScopes, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid scope.']);
    exit;
}

if ($cardIds === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Select at least one card.']);
    exit;
}

try {
    $infoDb = new PDO('sqlite:' . __DIR__ . '/info.db');
    $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'hide') {
        $count = uiHideCards($infoDb, $scope, $cardIds);
        echo json_encode(['ok' => true, 'message' => $count . ' card(s) hidden.', 'count' => $count]);
        exit;
    }
    if ($action === 'show') {
        $count = uiShowCards($infoDb, $scope, $cardIds);
        echo json_encode(['ok' => true, 'message' => $count . ' card(s) shown.', 'count' => $count]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid action.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
