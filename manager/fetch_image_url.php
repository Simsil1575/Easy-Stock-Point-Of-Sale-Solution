<?php

session_start();
date_default_timezone_set('Africa/Harare');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
$url = isset($payload['url']) ? trim((string) $payload['url']) : '';

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid image URL is required.']);
    exit();
}

$scheme = parse_url($url, PHP_URL_SCHEME);
if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only HTTP and HTTPS URLs are allowed.']);
    exit();
}

try {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "User-Agent: POS-BulkImageEditor/1.0\r\nAccept: image/*,*/*;q=0.8\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $binary = @file_get_contents($url, false, $context);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Could not download the image.');
    }

    if (strlen($binary) > 8 * 1024 * 1024) {
        throw new RuntimeException('Image is too large. Maximum size is 8MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($binary) ?: 'application/octet-stream';
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    if (!in_array($mimeType, $allowed, true)) {
        throw new RuntimeException('URL does not point to a supported image type.');
    }

    echo json_encode([
        'success' => true,
        'data_url' => 'data:' . $mimeType . ';base64,' . base64_encode($binary),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
