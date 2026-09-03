<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/barcode_labels_lib.php';
require_once __DIR__ . '/../includes/barcode_labels_pdf_lib.php';

$pdf = blCreateBarcodePdf();

try {
    $pdo = blGetDb();
    $stmt = $pdo->query("SELECT name, barcode, price FROM products WHERE barcode IS NOT NULL AND TRIM(barcode) != '' ORDER BY name COLLATE NOCASE");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error: ' . $e->getMessage());
}

$itemWidth = 90.0;
$itemHeight = 40.0;
$margin = 10.0;
$padding = 3.0;
$currentX = $margin;
$currentY = 40.0;

foreach ($products as $product) {
    if ($currentY + $itemHeight > $pdf->getPageHeight() - $margin) {
        $pdf->AddPage();
        $currentY = 40.0;
        $currentX = $margin;
    }
    if ($currentX + $itemWidth > $pdf->getPageWidth() - $margin) {
        $currentX = $margin;
        $currentY += $itemHeight + $margin;
        if ($currentY + $itemHeight > $pdf->getPageHeight() - $margin) {
            $pdf->AddPage();
            $currentY = 40.0;
        }
    }

    // Draw border around the label
    $pdf->Rect($currentX, $currentY, $itemWidth, $itemHeight, 'D');
    
    $contentY = $currentY + $padding;
    $contentX = $currentX + $padding;
    $contentWidth = $itemWidth - (2 * $padding);

    $pdf->setFont('helvetica', 'B', 10);
    $pdf->setXY($contentX, $contentY);
    $pdf->Cell($contentWidth, 5, (string) $product['name'], 0, 0, 'C');
    $contentY += 7;

    $barcodeValue = blSanitizeBarcodeValue((string) $product['barcode']);
    $barcodeWidth = $contentWidth - 2;
    $barcodeHeight = 18;
    $barcodeX = $contentX + 1;
    
    if (!blWriteCode128Barcode($pdf, $barcodeValue, $barcodeX, $contentY, $barcodeWidth, $barcodeHeight)) {
        $pdf->setFont('helvetica', '', 7);
        $pdf->setXY($contentX, $contentY);
        $pdf->Cell($contentWidth, 4, 'Barcode error: ' . $barcodeValue, 0, 0, 'C');
    }
    $contentY += $barcodeHeight + 2;

    $pdf->setFont('helvetica', 'B', 12);
    $pdf->setXY($contentX, $contentY);
    $pdf->Cell($contentWidth, 6, 'N$ ' . number_format((float) $product['price'], 2), 0, 0, 'C');

    $currentX += $itemWidth + $margin;
}

$pdf->Output('product_barcodes.pdf', 'D');
