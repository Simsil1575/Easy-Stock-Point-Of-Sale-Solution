<?php
header('Content-Type: application/json');
try {
if (!isset($FILES['image'])) {
throw new Exception('No image file uploaded');
}
$file = $FILES['image'];
$fileName = $file['name'];
$fileTmpName = $file['tmp_name'];
$fileError = $file['error'];
// Validate upload
if ($fileError !== UPLOAD_ERR_OK) {
throw new Exception('Upload failed with error code: ' . $fileError);
}
// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$fileType = mime_content_type($fileTmpName);
if (!in_array($fileType, $allowedTypes)) {
throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
}
// Generate unique filename
$extension = pathinfo($fileName, PATHINFO_EXTENSION);
$newFileName = uniqid() . '.' . $extension;
$uploadPath = 'products/' . $newFileName;
// Move uploaded file
if (!move_uploaded_file($fileTmpName, $uploadPath)) {
throw new Exception('Failed to move uploaded file');
}
// Convert WebP to JPG if necessary
if ($fileType === 'image/webp') {
$image = imagecreatefromwebp($uploadPath);
if ($image === false) {
throw new Exception('Failed to process WebP image');
}
$newFileName = pathinfo($newFileName, PATHINFO_FILENAME) . '.jpg';
$newPath = 'products/' . $newFileName;
imagejpeg($image, $newPath, 90);
imagedestroy($image);
unlink($uploadPath); // Remove original WebP file
}
echo json_encode([
'success' => true,
'url' => $newFileName
]);
} catch (Exception $e) {
http_response_code(400);
echo json_encode([
'success' => false,
'message' => $e->getMessage()
]);
}