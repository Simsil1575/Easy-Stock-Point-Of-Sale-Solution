<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Harare');

if (!isset($roleFolder) || !in_array($roleFolder, ['admin', 'manager', 'cashier'], true)) {
    $roleFolder = 'admin';
}

require_once __DIR__ . '/../../medical_aid_lib.php';

medicalAidRequireAccess();

$maBase = ($roleFolder === 'cashier') ? '' : '../';

try {
    $activationDb = new PDO('sqlite:' . __DIR__ . '/../../active.db');
    if ((int) $activationDb->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() === 0) {
        header('Location: ' . $maBase . 'settings');
        exit;
    }
} catch (Throwable $e) {
    // non-fatal
}

medicalAidBootstrap();
$db = medicalAidGetDb();
$backHref = ($roleFolder === 'cashier') ? 'cashier-center' : ($roleFolder . '-center');
$listPage = ($roleFolder === 'cashier') ? 'medical_aid' : 'medical_aid';
$viewPage = ($roleFolder === 'cashier') ? 'medical_aid_view' : 'medical_aid_view';
$editPage = ($roleFolder === 'cashier') ? 'medical_aid_edit' : 'medical_aid_edit';
$maCanDeletePatient = medicalAidCanDeletePatientFromSession();
$maRequiresVoidPinForDelete = medicalAidRequiresVoidPinToDeletePatient();
