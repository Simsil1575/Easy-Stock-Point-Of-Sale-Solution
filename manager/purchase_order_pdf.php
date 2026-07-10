<?php
session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../');
    exit;
}

require_once __DIR__ . '/../purchase_order_lib.php';
poRequireAdminOrManager();

$pdo = new PDO('sqlite:' . __DIR__ . '/../active.db');
if ((int) $pdo->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() === 0) {
    header('Location: settings');
    exit;
}

$db = new PDO('sqlite:' . __DIR__ . '/../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
require_once __DIR__ . '/../ensure_purchase_order_schema.php';
ensurePurchaseOrderSchema($db);

$poId = (int) ($_GET['id'] ?? 0);
if ($poId < 1) {
    http_response_code(400);
    exit('Invalid purchase order.');
}

$bundle = poLoadWithDetails($db, $poId);
if (!$bundle) {
    http_response_code(404);
    exit('Purchase order not found.');
}

if (!file_exists(__DIR__ . '/../fpdf/fpdf.php')) {
    http_response_code(500);
    exit('PDF library missing.');
}
require_once __DIR__ . '/../fpdf/fpdf.php';

$biz = poGetBusinessInfo(__DIR__ . '/../info.db');
$po = $bundle['po'];
$supplier = $bundle['supplier'];
$items = $bundle['items'];
$poLabel = poFormatNumber($poId);

class PurchaseOrderPDF extends FPDF
{
    /** @var array{name:string,location:string,phone:string,email:string} */
    public $biz;

    public function Header(): void
    {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, (string) $this->biz['name'], 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        if (!empty($this->biz['location'])) {
            $this->Cell(0, 5, (string) $this->biz['location'], 0, 1, 'C');
        }
        $contact = trim(($this->biz['phone'] ?? '') . (empty($this->biz['phone']) || empty($this->biz['email']) ? '' : ' · ') . ($this->biz['email'] ?? ''));
        if ($contact !== '') {
            $this->Cell(0, 5, $contact, 0, 1, 'C');
        }
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'PURCHASE ORDER', 0, 1, 'C');
        $this->Ln(2);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

$pdf = new PurchaseOrderPDF();
$pdf->biz = $biz;
$pdf->AliasNbPages();
$pdf->SetTitle('Purchase Order ' . $poLabel);
$pdf->AddPage();
$pdf->SetFont('Arial', '', 10);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 6, 'PO number:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, $poLabel, 0, 1);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 6, 'Status:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, (string) $po['status'], 0, 1);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 6, 'Order date:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, (string) $po['order_date'], 0, 1);

if (!empty($po['expected_date'])) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(40, 6, 'Expected:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, (string) $po['expected_date'], 0, 1);
}

$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'Supplier', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 5, (string) $supplier['name'], 0, 1);
if (!empty($supplier['phone'])) {
    $pdf->Cell(0, 5, 'Tel: ' . (string) $supplier['phone'], 0, 1);
}
if (!empty($supplier['email'])) {
    $pdf->Cell(0, 5, (string) $supplier['email'], 0, 1);
}

$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(85, 8, 'Product', 1);
$pdf->Cell(20, 8, 'Qty', 1, 0, 'C');
$pdf->Cell(35, 8, 'Unit cost', 1, 0, 'R');
$pdf->Cell(40, 8, 'Line total', 1, 0, 'R');
$pdf->Ln();

$pdf->SetFont('Arial', '', 9);
foreach ($items as $it) {
    $name = (string) $it['product_name'];
    if (strlen($name) > 48) {
        $name = substr($name, 0, 45) . '...';
    }
    $qty = (int) $it['quantity'];
    $uc = (float) $it['unit_cost'];
    $lt = (float) $it['line_total'];
    $pdf->Cell(85, 7, $name, 1);
    $pdf->Cell(20, 7, (string) $qty, 1, 0, 'C');
    $pdf->Cell(35, 7, 'N$' . number_format($uc, 2), 1, 0, 'R');
    $pdf->Cell(40, 7, 'N$' . number_format($lt, 2), 1, 0, 'R');
    $pdf->Ln();
}

if (empty($items)) {
    $pdf->Cell(180, 8, 'No line items', 1, 1, 'C');
}

$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(140, 7, 'Total', 1, 0, 'R');
$pdf->Cell(40, 7, 'N$' . number_format((float) $po['total_amount'], 2), 1, 0, 'R');
$pdf->Ln();

if (!empty($po['notes'])) {
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Notes', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, (string) $po['notes']);
}

$fileName = 'Purchase_Order_' . $poLabel . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
$pdf->Output('D', $fileName);
