<?php
require_once __DIR__ . '/cashier_helper.php';
requireApiSession();
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare');

require_once __DIR__ . '/medical_aid_lib.php';
require_once __DIR__ . '/recipe_stock_helper.php';
require_once __DIR__ . '/terminal_helper.php';

try {
    $db = medicalAidGetDb();
    configureSqlitePdo($db);
    medicalAidBootstrap();
    ensureTerminalSchema($db);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['patient_id'], $data['items'])) {
        throw new Exception('Missing required fields');
    }

    $patientId = (int) $data['patient_id'];
    $patient = medicalAidGetPatient($db, $patientId);
    if (!$patient || !(int) $patient['active']) {
        throw new Exception('Invalid or inactive patient');
    }

    $allowNegative = isSkipStockChecks($db);
    $cashierId = medicalAidCurrentUsername();
    $terminal = resolveTerminalFromRequest(is_array($data) ? $data : [], $db);

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO medical_aid_sales (patient_id, session_id, total_amount, paid_amount, payment_status, sale_type, cashier_id, created_at, notes)
        VALUES (?, NULL, 0, 0, 'unpaid', 'account', ?, ?, ?)
    ");
    $stmt->execute([
        $patientId,
        $cashierId,
        date('Y-m-d H:i:s'),
        'Account sale — ' . ($patient['scheme_name'] ?: 'Medical Aid'),
    ]);
    $saleId = (int) $db->lastInsertId();

    $total = medicalAidInsertSaleItems($db, $saleId, $data['items'], $cashierId, $allowNegative);

    $upd = $db->prepare("UPDATE medical_aid_sales SET total_amount = ? WHERE id = ?");
    $upd->execute([$total, $saleId]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'patient_name' => $patient['patient_name'],
        'scheme_name' => $patient['scheme_name'],
        'member_number' => $patient['member_number'],
        'auth_reference' => $patient['auth_reference'],
        'total' => $total,
        'payment_status' => 'unpaid',
        'sale_type' => 'medical_aid_account',
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
