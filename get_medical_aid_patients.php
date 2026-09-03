<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/medical_aid_lib.php';

try {
    medicalAidBootstrap();
    $db = medicalAidGetDb();
    $patients = medicalAidFetchPatientsWithBalances($db);
    echo json_encode(['success' => true, 'patients' => $patients]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
