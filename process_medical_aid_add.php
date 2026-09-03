<?php
require_once __DIR__ . '/cashier_helper.php';
requireApiSession();
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare');

require_once __DIR__ . '/medical_aid_lib.php';
require_once __DIR__ . '/recipe_stock_helper.php';

try {
    $db = medicalAidGetDb();
    configureSqlitePdo($db);
    medicalAidBootstrap();

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

    $db->beginTransaction();

    $sessionId = medicalAidOpenSession($db, $patientId, $cashierId);

    $existingSale = $db->prepare("
        SELECT id FROM medical_aid_sales
        WHERE session_id = ? AND payment_status != 'paid'
        ORDER BY id DESC LIMIT 1
    ");
    $existingSale->execute([$sessionId]);
    $saleId = (int) $existingSale->fetchColumn();

    if ($saleId <= 0) {
        $stmt = $db->prepare("
            INSERT INTO medical_aid_sales (patient_id, session_id, total_amount, paid_amount, payment_status, sale_type, cashier_id, created_at)
            VALUES (?, ?, 0, 0, 'unpaid', 'running', ?, ?)
        ");
        $stmt->execute([$patientId, $sessionId, $cashierId, date('Y-m-d H:i:s')]);
        $saleId = (int) $db->lastInsertId();
    }

    $addedTotal = medicalAidInsertSaleItems($db, $saleId, $data['items'], $cashierId, $allowNegative);

    $upd = $db->prepare("UPDATE medical_aid_sales SET total_amount = total_amount + ? WHERE id = ?");
    $upd->execute([$addedTotal, $saleId]);

    medicalAidRecalcSessionBalance($db, $sessionId);

    $balStmt = $db->prepare("SELECT current_balance FROM medical_aid_running_sessions WHERE id = ?");
    $balStmt->execute([$sessionId]);
    $sessionBalance = (float) $balStmt->fetchColumn();

    $db->commit();

    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'session_id' => $sessionId,
        'patient_name' => $patient['patient_name'],
        'added_total' => $addedTotal,
        'session_balance' => $sessionBalance,
        'scheme_name' => $patient['scheme_name'],
        'member_number' => $patient['member_number'],
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
