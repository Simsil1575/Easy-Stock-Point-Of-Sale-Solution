<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$cashierUsername = $_GET['cashier'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if (empty($cashierUsername)) {
    echo json_encode(['error' => 'No cashier specified']);
    exit();
}

try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $userDb = new PDO('sqlite:../user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    require_once __DIR__ . '/../user_shift_helper.php';

    $userId = resolveCashierUserId($userDb, $cashierUsername);
    
    if (!empty($startDate)) {
        $shiftData = getCashierShiftTimesForDate($db, $cashierUsername, $startDate, $endDate, $userId);
    } else {
        $shiftData = getCashierShiftTimes($db, $cashierUsername, $userId);
    }
    
    echo json_encode($shiftData);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
