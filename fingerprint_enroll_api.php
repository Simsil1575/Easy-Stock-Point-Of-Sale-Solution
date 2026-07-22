<?php
/**
 * Raw scans from browser → dpfj.dll enrollment FMD → v3 JSON in user.db (legacy v2 intermediate fallback).
 */
require_once __DIR__ . '/config.php';

$fpMatchEngineFile = __DIR__ . '/fingerprint_local_match.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($fpMatchEngineFile, true);
}
require $fpMatchEngineFile;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$role = (string) $_SESSION['role'];
$enrollRoles = ['admin', 'manager', 'cashier', 'waitress', 'hubbly'];
if (!in_array($role, $enrollRoles, true)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Your account role cannot enroll fingerprints. Use an administrator or manager account.',
    ]);
    exit;
}

if (empty($_POST['data'])) {
    echo json_encode(['ok' => false, 'error' => 'No enrollment data']);
    exit;
}

$data = json_decode($_POST['data']);
if (!$data || !isset($data->index_finger, $data->middle_finger)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid enrollment payload']);
    exit;
}

$indexSamples = json_decode(json_encode($data->index_finger), true);
$middleSamples = json_decode(json_encode($data->middle_finger), true);
if (!is_array($indexSamples) || !is_array($middleSamples)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid samples']);
    exit;
}

$keep = fp_enroll_settings()['enroll_samples_keep'];
if (count($indexSamples) < $keep || count($middleSamples) < $keep) {
    echo json_encode([
        'ok' => false,
        'error' => 'Capture ' . $keep . ' scans per finger (index and middle).',
    ]);
    exit;
}

try {
    $packed = fp_pack_client_samples_v2($indexSamples, $middleSamples);
} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    error_log('fingerprint_enroll_api: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not process enrollment templates.']);
    exit;
}

$format = 'dp_intermediate';
$idxDecoded = json_decode($packed['indexfinger'], true);
if (is_array($idxDecoded) && isset($idxDecoded['format'])) {
    $format = (string) $idxDecoded['format'];
}

echo json_encode([
    'ok' => true,
    'format' => $format,
    'enrolled_index_finger' => $packed['indexfinger'],
    'enrolled_middle_finger' => $packed['middlefinger'],
    'native' => function_exists('fp_native_status') ? fp_native_status() : null,
]);
