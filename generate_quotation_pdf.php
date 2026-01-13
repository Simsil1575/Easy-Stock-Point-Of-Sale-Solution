<?php
require('fpdf/fpdf.php');

// Get the POST data
$data = json_decode(file_get_contents('php://input'), true);

// Create new PDF document
class PDF extends FPDF {
    function Header() {
        // Business Info
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'Is 2 Do Bar', 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 6, 'Oshakati, Namibia', 0, 1, 'C');
        $this->Cell(0, 6, 'Tel: +264812772340', 0, 1, 'C');
        $this->Cell(0, 6, date('Y-m-d H:i:s'), 0, 1, 'C');
        $this->Ln(10);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Thank you for your business!', 0, 0, 'C');
        $this->Ln(5);
        $this->Cell(0, 10, 'Much Appreciated!!', 0, 0, 'C');
    }
}

// Create PDF
$pdf = new PDF();
$pdf->AddPage();

// Title
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'QUOTATION', 0, 1, 'C');
$pdf->Ln(5);

// Table header
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(80, 7, 'Item', 1);
$pdf->Cell(30, 7, 'Qty', 1);
$pdf->Cell(40, 7, 'Price', 1);
$pdf->Cell(40, 7, 'Total', 1);
$pdf->Ln();

// Table data
$pdf->SetFont('Arial', '', 10);
$total = 0;
foreach ($data['items'] as $item) {
    $pdf->Cell(80, 6, $item['name'], 1);
    $pdf->Cell(30, 6, $item['quantity'], 1);
    $pdf->Cell(40, 6, 'N$' . number_format($item['price']/$item['quantity'], 2), 1);
    $pdf->Cell(40, 6, 'N$' . number_format($item['price'], 2), 1);
    $pdf->Ln();
    $total += $item['price'];
}

// Totals
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(150, 7, 'Subtotal:', 1);
$pdf->Cell(40, 7, 'N$' . number_format($total, 2), 1);
$pdf->Ln();

$pdf->Cell(150, 7, 'Cash Received:', 1);
$pdf->Cell(40, 7, 'N$' . number_format($data['cash_received'], 2), 1);
$pdf->Ln();

$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(150, 7, 'Change:', 1);
$pdf->Cell(40, 7, 'N$' . number_format($data['cash_received'] - $total, 2), 1);
$pdf->Ln();

// Output PDF
$pdf->Output('D', 'quotation.pdf');
?> 