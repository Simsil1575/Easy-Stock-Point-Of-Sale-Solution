<?php

session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header('Location: ../');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings?s=system');
    exit();
}

function isDefaultProductImage(?string $imageUrl): bool
{
    if ($imageUrl === null || $imageUrl === '') {
        return true;
    }

    return $imageUrl === 'default.png'
        || strpos($imageUrl, 'default.png') !== false;
}

function sanitizeProductImageFilename(string $productName): string
{
    $safe = strtolower(trim($productName));
    $safe = preg_replace('/[^a-z0-9]+/', '_', $safe);
    $safe = trim($safe, '_');

    return $safe !== '' ? $safe : 'product';
}

function redirectWithExportResult(string $status, array $data = []): void
{
    $params = array_merge(['s' => 'system', 'images_export' => $status], $data);
    header('Location: settings?' . http_build_query($params));
    exit();
}

$exportDirName = 'product_image_exports';
$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    redirectWithExportResult('error', ['message' => 'Could not resolve project folder.']);
}

$exportDir = $projectRoot . DIRECTORY_SEPARATOR . $exportDirName;
if (!is_dir($exportDir) && !mkdir($exportDir, 0755, true)) {
    redirectWithExportResult('error', ['message' => 'Could not create export folder.']);
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $products = $db->query('SELECT id, name, image_url FROM products ORDER BY name ASC')
        ->fetchAll(PDO::FETCH_ASSOC);

    $exported = 0;
    $skipped = 0;
    $failed = 0;
    $usedNames = [];

    foreach ($products as $product) {
        if (isDefaultProductImage($product['image_url'])) {
            $skipped++;
            continue;
        }

        $sourcePath = $projectRoot . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . basename((string) $product['image_url']);
        if (!is_file($sourcePath)) {
            $skipped++;
            continue;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        if (!in_array($ext, $allowedExtensions, true)) {
            $ext = 'png';
        }

        $baseName = sanitizeProductImageFilename((string) $product['name']);
        $fileName = $baseName . '.' . $ext;

        if (isset($usedNames[$fileName])) {
            $counter = 2;
            do {
                $fileName = $baseName . '_' . $counter . '.' . $ext;
                $counter++;
            } while (isset($usedNames[$fileName]));
        }
        $usedNames[$fileName] = true;

        $destPath = $exportDir . DIRECTORY_SEPARATOR . $fileName;
        if (@copy($sourcePath, $destPath)) {
            $exported++;
            continue;
        }

        $failed++;
    }

    redirectWithExportResult('success', [
        'exported' => $exported,
        'skipped' => $skipped,
        'failed' => $failed,
        'folder' => $exportDirName,
    ]);
} catch (Throwable $e) {
    redirectWithExportResult('error', ['message' => $e->getMessage()]);
}
