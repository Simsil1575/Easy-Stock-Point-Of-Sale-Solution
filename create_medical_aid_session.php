<?php
require_once __DIR__ . '/cashier_helper.php';
requireApiSession();
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare');

require_once __DIR__ . '/medical_aid_lib.php';

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!isset($data['patient_id'])) {
        throw new Exception('patient_id is required');
    }

    medicalAidBootstrap();
    $db = medicalAidGetDb();
    $patientId = (int) $data['patient_id'];
    $patient = medicalAidGetPatient($db, $patientId);
    if (!$patient || !(int) $patient['active']) {
        throw new Exception('Invalid or inactive patient');
    }

    $sessionId = medicalAidOpenSession($db, $patientId, medicalAidCurrentUsername());
    $session = $db->prepare("SELECT * FROM medical_aid_running_sessions WHERE id = ?");
    $session->execute([$sessionId]);
    $sessionRow = $session->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'session' => $sessionRow]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
