<?php
require_once __DIR__ . '/cashier_helper.php';
requireApiSession();
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare');

require_once __DIR__ . '/medical_aid_lib.php';

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string) ($data['patient_name'] ?? ''));
    if ($name === '') {
        throw new Exception('Patient name is required');
    }

    medicalAidBootstrap();
    $db = medicalAidGetDb();
    $id = medicalAidCreatePatient($db, $data);
    $patient = medicalAidGetPatient($db, $id);

    echo json_encode(['success' => true, 'patient' => $patient]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
