<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/barcode_labels_lib.php';
require_once __DIR__ . '/includes/barcode_labels_pdf_lib.php';

blRequireAdminOrManager();

$db = blGetDb();
$ids = $_GET['ids'] ?? [];
if (!is_array($ids)) {
    $ids = explode(',', (string) $ids);
}
$ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));

if ($ids === []) {
    die('No products selected.');
}

$products = blGetProductsByIds($db, $ids);
$settings = blGetSettings($db);
$printOptions = blMergePrintOptions($settings, $_GET);
$labelOpts = blNormalizeLabelOptions($printOptions);
$products = array_values(array_filter($products, fn($p) => blProductPrintable($p, $printOptions)));

if ($products === []) {
    die('No printable labels for the selected products and fields.');
}

$showName = $labelOpts['show_name'];
$showBarcode = $labelOpts['show_barcode'];
$showPrice = $labelOpts['show_price'];

$pdf = blCreateBarcodePdf();

$itemWidth = 90.0;
$itemHeight = 33.0;
$margin = 10.0;
$padding = 2.0;
$currentX = $margin;
$currentY = 36.0;

foreach ($products as $product) {
    if ($currentY + $itemHeight > $pdf->getPageHeight() - $margin) {
        $pdf->AddPage();
        $currentY = 36.0;
        $currentX = $margin;
    }
    if ($currentX + $itemWidth > $pdf->getPageWidth() - $margin) {
        $currentX = $margin;
        $currentY += $itemHeight + $margin;
        if ($currentY + $itemHeight > $pdf->getPageHeight() - $margin) {
            $pdf->AddPage();
            $currentY = 36.0;
        }
    }

    // Draw border around the label
    $pdf->Rect($currentX, $currentY, $itemWidth, $itemHeight, 'D');
    
    $contentY = $currentY + $padding;
    $contentX = $currentX + $padding;
    $contentWidth = $itemWidth - (2 * $padding);

    // Render barcode first
    if ($showBarcode && trim((string) $product['barcode']) !== '') {
        $barcodeValue = blSanitizeBarcodeValue((string) $product['barcode']);
        $barcodeWidth = $contentWidth;
        $barcodeHeight = 20;
        $barcodeX = $contentX;
        
        if (!blWriteCode128Barcode($pdf, $barcodeValue, $barcodeX, $contentY, $barcodeWidth, $barcodeHeight)) {
            $pdf->setFont('times', '', 7);
            $pdf->setXY($contentX, $contentY);
            $pdf->Cell($contentWidth, 4, 'Barcode error: ' . $barcodeValue, 0, 0, 'C');
        }
        $contentY += $barcodeHeight + 2;
    }

    // Render name and price on the same row below barcode
    if ($showName && $showPrice) {
        // Name on the left
        $pdf->setFont('times', 'B', 12);
        $pdf->setXY($contentX, $contentY);
        $nameWidth = $contentWidth * 0.55;
        $pdf->Cell($nameWidth, 7, (string) $product['name'], 0, 0, 'L');
        
        // Price on the right (bigger)
        $pdf->setFont('times', 'B', 15);
        $priceWidth = $contentWidth * 0.45;
        $pdf->setXY($contentX + $nameWidth, $contentY);
        $pdf->Cell($priceWidth, 7, 'N$ ' . number_format((float) $product['price'], 2), 0, 0, 'R');
    } elseif ($showName) {
        // Only name
        $pdf->setFont('times', 'B', 12);
        $pdf->setXY($contentX, $contentY);
        $pdf->Cell($contentWidth, 7, (string) $product['name'], 0, 0, 'C');
    } elseif ($showPrice) {
        // Only price
        $pdf->setFont('times', 'B', 15);
        $pdf->setXY($contentX, $contentY);
        $pdf->Cell($contentWidth, 7, 'N$ ' . number_format((float) $product['price'], 2), 0, 0, 'C');
    }

    $currentX += $itemWidth + $margin;
}

$pdf->Output('product_barcodes.pdf', 'D');
