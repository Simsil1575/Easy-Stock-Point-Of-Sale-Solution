<?php

declare(strict_types=1);

require_once __DIR__ . '/barcode_labels_lib.php';
require_once __DIR__ . '/../vendor/autoload.php';

class BlBarcodeSheetPdf extends TCPDF
{
    public function Header(): void
    {
        $this->setFont('helvetica', 'B', 18);
        $this->Cell(0, 12, 'Product Barcodes', 0, false, 'C');
        $this->Ln(16);
    }
}

function blCreateBarcodePdf(): BlBarcodeSheetPdf
{
    $pdf = new BlBarcodeSheetPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(false);
    $pdf->setTitle('Product Barcodes');
    $pdf->setCreator('POS System');
    $pdf->setAuthor('POS System');
    $pdf->setMargins(10, 30, 10);
    $pdf->setAutoPageBreak(true, 10);
    $pdf->AddPage();

    return $pdf;
}

function blWriteCode128Barcode(TCPDF $pdf, string $barcode, float $x, float $y, float $w, float $h): bool
{
    $barcode = blSanitizeBarcodeValue($barcode);
    if ($barcode === '') {
        return false;
    }

    $style = [
        'border' => false,
        'padding' => 0,
        'hpadding' => 0,
        'vpadding' => 0,
        'fgcolor' => [0, 0, 0],
        'bgcolor' => false,
        'text' => true,
        'font' => 'helvetica',
        'fontsize' => 8,
        'stretchtext' => 4,
    ];

    try {
        // Calculate center position for the barcode
        // Estimate barcode width (rough approximation for Code128)
        $barcodeNaturalWidth = (strlen($barcode) * 1.5) + 10; // mm approximate
        
        // Center it within the available width
        $centeredX = $x + ($w - min($barcodeNaturalWidth, $w)) / 2;
        
        $pdf->write1DBarcode($barcode, 'C128', $centeredX, $y, 0, $h, 0.4, $style, 'N');
        
        return true;
    } catch (Throwable $e) {
        error_log('Barcode PDF render error: ' . $e->getMessage());
        return false;
    }
}
