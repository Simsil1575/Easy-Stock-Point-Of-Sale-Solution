<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once __DIR__ . '/medical_aid_lib.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['patient_id'], $input['amount'])) {
    echo json_encode(['success' => false, 'message' => 'Missing patient_id or amount']);
    exit;
}

$patientId = (int) $input['patient_id'];
$amount = (float) $input['amount'];
$reference = trim((string) ($input['payment_reference'] ?? ''));
$schemeName = trim((string) ($input['scheme_name'] ?? ''));

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit;
}

try {
    medicalAidBootstrap();
    $db = medicalAidGetDb();
    $patient = medicalAidGetPatient($db, $patientId);
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit;
    }

    $db->beginTransaction();
    $result = medicalAidAllocatePayment(
        $db,
        $patientId,
        $amount,
        $reference,
        $schemeName ?: (string) ($patient['scheme_name'] ?? ''),
        medicalAidCurrentUsername()
    );
    $db->commit();

    echo json_encode([
        'success' => true,
        'patient_name' => $patient['patient_name'],
        'amount' => $result['allocated_total'],
        'payment_id' => $result['payment_id'],
        'allocated' => $result['allocated'],
        'outstanding_before' => $result['outstanding_before'],
        'outstanding_after' => $result['outstanding_after'],
        'message' => medicalAidDescribePaymentResult($result),
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
