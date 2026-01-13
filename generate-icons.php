<?php
// This script generates PWA icons from your existing logo.png file

// Check if GD library is available
if (!extension_loaded('gd')) {
    die('GD library is not available. Please install it to generate icons.');
}

// Source image
$source = 'logo.png';

// Check if source image exists
if (!file_exists($source)) {
    die('Source image not found: ' . $source);
}

// Create icons directory if it doesn't exist
if (!is_dir('icons')) {
    mkdir('icons', 0755, true);
}

// Icon sizes to generate
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];

// Load the source image
$sourceImage = imagecreatefrompng($source);
if (!$sourceImage) {
    die('Failed to load source image');
}

// Get source image dimensions
$sourceWidth = imagesx($sourceImage);
$sourceHeight = imagesy($sourceImage);

// Generate icons for each size
foreach ($sizes as $size) {
    // Create a new image with the target size
    $targetImage = imagecreatetruecolor($size, $size);
    
    // Preserve transparency
    imagealphablending($targetImage, false);
    imagesavealpha($targetImage, true);
    $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
    imagefilledrectangle($targetImage, 0, 0, $size, $size, $transparent);
    
    // Resize the image
    imagecopyresampled(
        $targetImage, $sourceImage,
        0, 0, 0, 0,
        $size, $size, $sourceWidth, $sourceHeight
    );
    
    // Save the image
    $filename = "icons/icon-{$size}x{$size}.png";
    imagepng($targetImage, $filename);
    imagedestroy($targetImage);
    
    echo "Generated: $filename\n";
}

// Generate offline image
$offlineImage = imagecreatetruecolor(200, 200);
$bgColor = imagecolorallocate($offlineImage, 240, 240, 240);
$textColor = imagecolorallocate($offlineImage, 100, 100, 100);
imagefilledrectangle($offlineImage, 0, 0, 200, 200, $bgColor);
imagestring($offlineImage, 5, 40, 90, "Image Unavailable", $textColor);
imagepng($offlineImage, "icons/offline-image.png");
imagedestroy($offlineImage);
echo "Generated: icons/offline-image.png\n";

// Clean up
imagedestroy($sourceImage);
echo "All icons generated successfully!\n";
?> 