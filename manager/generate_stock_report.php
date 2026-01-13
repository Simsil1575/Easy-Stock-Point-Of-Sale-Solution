<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Include FPDF library
require_once('../fpdf/fpdf.php');

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Create PDF class extending FPDF
class StockReportPDF extends FPDF {
    function Header() {
        // Logo
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(0, 10, 'STOCK ALERT REPORT', 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
        $this->Ln(10);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    function ChapterTitle($title, $color) {
        $this->SetFont('Arial', 'B', 14);
        $this->SetFillColor($color[0], $color[1], $color[2]);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 8, $title, 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(5);
    }
    
    function TableHeader() {
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(50, 8, 'Product Name', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Category', 1, 0, 'C', true);
        $this->Cell(20, 8, 'Quantity', 1, 0, 'C', true);
        $this->Cell(20, 8, 'Restock Level', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Price (N$)', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Buying Price', 1, 0, 'C', true);
        $this->Cell(20, 8, 'Status', 1, 1, 'C', true);
    }
    
    function TableRow($data, $statusColor) {
        $this->SetFont('Arial', '', 8);
        $this->Cell(50, 8, $data['name'], 1, 0, 'L');
        $this->Cell(25, 8, $data['category'] ?? 'N/A', 1, 0, 'C');
        $this->Cell(20, 8, $data['quantity'], 1, 0, 'C');
        $this->Cell(20, 8, $data['restock_level'] ?? '5', 1, 0, 'C');
        $this->Cell(25, 8, number_format($data['price'], 2), 1, 0, 'R');
        $this->Cell(25, 8, number_format($data['buying_price'] ?? 0, 2), 1, 0, 'R');
        
        // Status with color
        $this->SetFillColor($statusColor[0], $statusColor[1], $statusColor[2]);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(20, 8, $data['status'], 1, 1, 'C', true);
        $this->SetTextColor(0, 0, 0);
    }
}

// Fetch products from the database with accurate stock levels and restock level
$stmt = $db->query('
    SELECT 
        p.*,
        COALESCE(SUM(oi.quantity), 0) as total_sold,
        CASE 
            WHEN p.quantity <= 0 THEN "Out of Stock"
            WHEN p.quantity < COALESCE(p.restock_level, 5) THEN "Low Stock"
            ELSE "In Stock"
        END as status
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
    GROUP BY p.id
    HAVING p.quantity <= 0 OR p.quantity < COALESCE(p.restock_level, 5)
    ORDER BY p.quantity ASC, p.name ASC
');

$lowStock = [];
$outOfStock = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $restockLevel = $row['restock_level'] ?? 5; // Default to 5 if not set
    
    if ($row['quantity'] <= 0) {
        $outOfStock[] = $row;
    } else if ($row['quantity'] < $restockLevel) {
        $lowStock[] = $row;
    }
}

// Create PDF
$pdf = new StockReportPDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Add summary section
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'SUMMARY', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 8, 'Total Out of Stock Items: ' . count($outOfStock), 0, 1, 'L');
$pdf->Cell(0, 8, 'Total Low Stock Items: ' . count($lowStock), 0, 1, 'L');
$pdf->Cell(0, 8, 'Total Alert Items: ' . (count($outOfStock) + count($lowStock)), 0, 1, 'L');
$pdf->Cell(0, 8, 'Note: Low stock is determined by individual product restock levels', 0, 1, 'L');
$pdf->Ln(10);

// Out of Stock Section
if (!empty($outOfStock)) {
    $pdf->ChapterTitle('OUT OF STOCK ITEMS (' . count($outOfStock) . ')', [220, 53, 69]); // Red color
    
    $pdf->TableHeader();
    
    foreach ($outOfStock as $product) {
        $pdf->TableRow($product, [220, 53, 69]); // Red color for out of stock
    }
    
    $pdf->Ln(10);
}

// Low Stock Section
if (!empty($lowStock)) {
    $pdf->ChapterTitle('LOW STOCK ITEMS (' . count($lowStock) . ')', [255, 193, 7]); // Yellow color
    
    $pdf->TableHeader();
    
    foreach ($lowStock as $product) {
        $pdf->TableRow($product, [255, 193, 7]); // Yellow color for low stock
    }
    
    $pdf->Ln(10);
}

// Add recommendations section
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'RECOMMENDATIONS', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 8, '1. Restock out of stock items immediately to avoid lost sales', 0, 1, 'L');
$pdf->Cell(0, 8, '2. Monitor low stock items and reorder before they run out', 0, 1, 'L');
$pdf->Cell(0, 8, '3. Consider setting up automatic reorder points for critical items', 0, 1, 'L');
$pdf->Cell(0, 8, '4. Review pricing strategy for items with low stock levels', 0, 1, 'L');

// Generate filename with timestamp
$filename = 'stock_alert_report_' . date('Y-m-d_H-i-s') . '.pdf';

// Output PDF
$pdf->Output('D', $filename);
exit;
?> 