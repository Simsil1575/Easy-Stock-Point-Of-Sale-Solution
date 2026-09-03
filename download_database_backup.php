<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    exit('Forbidden');
}

$role = strtolower((string) $_SESSION['role']);
if (!in_array($role, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/database_backup_helper.php';

$folder = isset($_GET['folder']) ? (string) $_GET['folder'] : '';
$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
$zip = isset($_GET['zip']) && $_GET['zip'] === '1';

$folderPath = getDatabaseBackupFolderPath($folder);
if ($folderPath === null) {
    http_response_code(404);
    exit('Backup not found');
}

if ($zip) {
    $zipPath = createDatabaseBackupZip($folder);
    if ($zipPath === null || !is_file($zipPath)) {
        http_response_code(500);
        exit('Could not create zip');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $folder . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

if ($file === '' || !in_array($file, DATABASE_BACKUP_FILES, true) && $file !== 'manifest.json') {
    http_response_code(400);
    exit('Invalid file');
}

$filePath = $folderPath . DIRECTORY_SEPARATOR . $file;
if (!is_file($filePath)) {
    http_response_code(404);
    exit('File not found');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
