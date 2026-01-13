<?php
// net_profit_pdf.php - Generate PDF net profit report using FPDF

require('../fpdf/fpdf.php');

class NetProfitPDF extends FPDF {
    // Page header
    function Header() {
        // Title
        $this->SetFont('Arial', 'B', 18);
        $this->Cell(0, 15, 'Net Profit Report', 0, 1, 'C');
        
        // Date
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 8, 'Period: ' . $_GET['date_display'], 0, 1, 'C');
        $this->Ln(10);
    }

    // Page footer
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

// Get parameters from GET request
$view = isset($_GET['view']) ? $_GET['view'] : 'daily';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$dateDisplay = isset($_GET['date_display']) ? $_GET['date_display'] : date('l, F j, Y');

// Function to get total sales
function getCashSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(total), 0) 
                 FROM orders 
                 WHERE created_at BETWEEN :start_date AND :end_date)
                +
                (SELECT COALESCE(SUM(amount), 0) 
                 FROM payments 
                 WHERE payment_date BETWEEN :start_date AND :end_date)
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCashSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales
function getCreditSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total_amount - paid_amount), 0)
            FROM credit_sales 
            WHERE created_at BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCreditSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cost of goods sold
function getCostOfGoodsSold($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(oi.quantity * COALESCE(oi.buying_price, 0)), 0)
                 FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 WHERE o.created_at BETWEEN :start_date AND :end_date)
                +
                (SELECT COALESCE(SUM(csi.quantity * COALESCE(csi.buying_price, 0)), 0)
                 FROM credit_sale_items csi
                 JOIN credit_sales cs ON csi.sale_id = cs.id
                 WHERE cs.created_at BETWEEN :start_date AND :end_date)
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCostOfGoodsSold: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cash in
function getTotalCashIn($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-in' AND created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashIn: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cash out
function getTotalCashOut($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashOut: " . $e->getMessage());
        return 0;
    }
}

// Function to get top-selling products
function getTopSellingProducts($db, $startDate, $endDate, $limit = 10000) {
    try {
        $stmt = $db->prepare("
            SELECT 
                combined.product_name, 
                SUM(combined.quantity) as total_quantity,
                AVG(combined.unit_price) as unit_price,
                AVG(combined.unit_cost) as unit_cost
            FROM (
                SELECT 
                    oi.product_name, 
                    oi.quantity,
                    oi.price as unit_price,
                    COALESCE(oi.buying_price, 0) as unit_cost
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.created_at BETWEEN :start_date AND :end_date
                UNION ALL
                SELECT 
                    csi.product_name, 
                    csi.quantity,
                    csi.price as unit_price,
                    COALESCE(csi.buying_price, 0) as unit_cost
                FROM credit_sale_items csi
                JOIN credit_sales cs ON csi.sale_id = cs.id
                WHERE cs.created_at BETWEEN :start_date AND :end_date
            ) combined
            GROUP BY combined.product_name
            ORDER BY total_quantity DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':start_date', $startDate . ' 00:00:00');
        $stmt->bindValue(':end_date', $endDate . ' 23:59:59');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getTopSellingProducts: " . $e->getMessage());
        return [];
    }
}

// Get opening, purchases, and closing inventory
$openingInventory = 0;
$purchases = 0;
$closingInventory = 0;

try {
    $stmt = $db->prepare("SELECT SUM(opening_quantity) as opening, SUM(received_quantity) as purchases, SUM(closing_quantity) as closing FROM daily_stock_summary WHERE date BETWEEN :start AND :end");
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $openingInventory = $row['opening'] ?? 0;
    $purchases = $row['purchases'] ?? 0;
    $closingInventory = $row['closing'] ?? 0;
} catch (Exception $e) {
    // Leave as 0 if error
}

// Retrieve values
$cashSales = getCashSales($db, $startDate, $endDate);
$creditSales = getCreditSales($db, $startDate, $endDate);
$costOfGoodsSold = getCostOfGoodsSold($db, $startDate, $endDate);
$totalRevenue = $cashSales + $creditSales;
$grossProfit = $totalRevenue - $costOfGoodsSold;
$topSellingProducts = getTopSellingProducts($db, $startDate, $endDate, 100000);
$totalCashIn = getTotalCashIn($db, $startDate, $endDate);
$totalCashOut = getTotalCashOut($db, $startDate, $endDate);
$netProfit = $grossProfit + $totalCashIn - $totalCashOut;

// Calculate margins
$grossMarginPct = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;
$netMarginPct = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

// Create new PDF instance
$pdf = new NetProfitPDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Set font
$pdf->SetFont('Arial', '', 12);

// Summary Cards Section
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Financial Summary', 0, 1, 'C');
$pdf->Ln(5);

// Revenue
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(60, 8, 'Total Revenue:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(40, 8, 'N$' . number_format($totalRevenue, 2), 0, 1, 'R');

// Gross Profit
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(60, 8, 'Gross Profit:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(40, 8, 'N$' . number_format($grossProfit, 2), 0, 1, 'R');

// Gross Margin
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(60, 6, 'Gross Margin:', 0, 0, 'L');
$pdf->Cell(40, 6, number_format($grossMarginPct, 1) . '%', 0, 1, 'R');

$pdf->Ln(3);

// Net Profit
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(60, 8, 'Net Profit:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(40, 8, 'N$' . number_format($netProfit, 2), 0, 1, 'R');

// Net Margin
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(60, 6, 'Net Margin:', 0, 0, 'L');
$pdf->Cell(40, 6, number_format($netMarginPct, 1) . '%', 0, 1, 'R');

$pdf->Ln(15);

// Top Selling Products Section
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'All Selling Products', 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 10);
$pdf->Cell(0, 6, 'Total Products: ' . count($topSellingProducts), 0, 1, 'C');
$pdf->Ln(5);

// Table header
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(60, 8, 'Product', 1, 0, 'C');
$pdf->Cell(25, 8, 'Quantity', 1, 0, 'C');
$pdf->Cell(30, 8, 'Revenue', 1, 0, 'C');
$pdf->Cell(30, 8, 'Profit', 1, 0, 'C');
$pdf->Cell(25, 8, 'Margin %', 1, 1, 'C');

// Table data
$pdf->SetFont('Arial', '', 9);
foreach ($topSellingProducts as $index => $product) {
    // Check if we need a new page
    if ($pdf->GetY() > 250) {
        $pdf->AddPage();
        // Reprint table header on new page
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(60, 8, 'Product', 1, 0, 'C');
        $pdf->Cell(25, 8, 'Quantity', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Revenue', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Profit', 1, 0, 'C');
        $pdf->Cell(25, 8, 'Margin %', 1, 1, 'C');
        $pdf->SetFont('Arial', '', 9);
    }
    
    $revenue = ($product['total_quantity'] ?? 0) * ($product['unit_price'] ?? 0);
    $cost = ($product['total_quantity'] ?? 0) * ($product['unit_cost'] ?? 0);
    $profit = $revenue - $cost;
    $marginPct = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
    
    $pdf->Cell(60, 6, substr($product['product_name'], 0, 25), 1, 0, 'L');
    $pdf->Cell(25, 6, number_format($product['total_quantity']), 1, 0, 'R');
    $pdf->Cell(30, 6, 'N$' . number_format($revenue, 2), 1, 0, 'R');
    $pdf->Cell(30, 6, 'N$' . number_format($profit, 2), 1, 0, 'R');
    $pdf->Cell(25, 6, number_format($marginPct, 1) . '%', 1, 1, 'R');
}

$pdf->Ln(15);

// Add timestamp
$pdf->SetFont('Arial', 'I', 10);
$pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');

// Add signature lines
$pdf->Ln(20);
$pdf->Cell(95, 10, '____________________', 0, 0, 'C');
$pdf->Cell(95, 10, '____________________', 0, 1, 'C');
$pdf->Cell(95, 10, 'Manager Signature', 0, 0, 'C');
$pdf->Cell(95, 10, 'Date', 0, 1, 'C');

// Output PDF to browser for download
$filename = 'Net_Profit_Report_' . str_replace([' ', ',', ':', '/'], '_', $dateDisplay) . '.pdf';
$pdf->Output('D', $filename);
?>
