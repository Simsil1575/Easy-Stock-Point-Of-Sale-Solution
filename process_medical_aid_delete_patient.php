<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once __DIR__ . '/medical_aid_lib.php';

medicalAidBootstrap();
$db = medicalAidGetDb();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['patient_id'])) {
        throw new Exception('Missing patient_id');
    }

    $patientId = (int) $data['patient_id'];
    $pin = trim((string) ($data['manager_pin'] ?? ''));

    medicalAidAssertPatientDeleteAllowed($pin);

    $db->beginTransaction();
    try {
        $result = medicalAidDeletePatient($db, $patientId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $message = $result['mode'] === 'deactivated'
        ? 'Patient deactivated — transaction history retained.'
        : 'Patient permanently removed.';

    echo json_encode([
        'success' => true,
        'mode' => $result['mode'],
        'message' => $message,
        'patient_name' => $result['patient_name'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
