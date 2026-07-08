<?php
// product_sales_pdf.php - Generate PDF for specific product sales report using FPDF

require('../fpdf/fpdf.php');

class ProductSalesPDF extends FPDF {
    // Page header
    function Header() {
        // Title
        $this->SetFont('Arial', 'B', 18);
        $this->Cell(0, 15, 'Product Sales Report', 0, 1, 'C');
        
        // Product Name
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, htmlspecialchars_decode($this->productName, ENT_QUOTES), 0, 1, 'C');
        
        // Date Range
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 8, 'Period: ' . $this->dateDisplay, 0, 1, 'C');
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

// Get business closing time from business_info
$businessInfo = [];
$closingTime = '00:00'; // Default
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($businessInfo && isset($businessInfo['closing_time'])) {
        $closingTime = $businessInfo['closing_time'];
    }
} catch (PDOException $e) {
    error_log('Business info DB error: ' . $e->getMessage());
    // Continue with default closing time
}

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;

// Business day WHERE clause helper
class BusinessDayCache {
    private static $cache = [];
    
    public static function getWhereClause($dateField, $startDate, $endDate, $closingTime, $isAfterMidnight) {
        $cacheKey = "$dateField-$startDate-$endDate-$closingTime-$isAfterMidnight";
        
        if (!isset(self::$cache[$cacheKey])) {
            if ($startDate === $endDate) {
                // Single day - use business day logic
                $nextDay = date('Y-m-d', strtotime($startDate . ' +1 day'));
                self::$cache[$cacheKey] = "
                    (DATE($dateField) = '$startDate' AND strftime('%H:%M', $dateField) >= '$closingTime') OR
                    (DATE($dateField) = '$nextDay' AND strftime('%H:%M', $dateField) < '$closingTime' AND " . ($isAfterMidnight ? "1" : "0") . " = 1)
                ";
            } else {
                // Multiple days - need to handle each day's business hours
                $whereClauses = [];
                $currentDate = new DateTime($startDate);
                $endDateTime = new DateTime($endDate);
                
                while ($currentDate <= $endDateTime) {
                    $dateStr = $currentDate->format('Y-m-d');
                    $nextDay = clone $currentDate;
                    $nextDay->modify('+1 day');
                    $nextDayStr = $nextDay->format('Y-m-d');
                    
                    $whereClauses[] = "
                        (DATE($dateField) = '$dateStr' AND strftime('%H:%M', $dateField) >= '$closingTime') OR
                        (DATE($dateField) = '$nextDayStr' AND strftime('%H:%M', $dateField) < '$closingTime' AND " . ($isAfterMidnight ? "1" : "0") . " = 1)
                    ";
                    
                    $currentDate->modify('+1 day');
                }
                
                self::$cache[$cacheKey] = "(" . implode(") OR (", $whereClauses) . ")";
            }
        }
        
        return self::$cache[$cacheKey];
    }
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
$productName = isset($_GET['product_name']) ? urldecode($_GET['product_name']) : '';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

if (empty($productName)) {
    die("Product name is required");
}

// Format date display
$dateDisplay = ($startDate === $endDate) 
    ? date('l, F j, Y', strtotime($startDate))
    : date('F j, Y', strtotime($startDate)) . ' - ' . date('F j, Y', strtotime($endDate));

// Function to get product sales data
function getProductSalesData($db, $productName, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT 
                combined.date,
                SUM(combined.quantity) as total_quantity,
                SUM(combined.revenue) as total_revenue
            FROM (
                SELECT 
                    DATE(o.created_at) as date,
                    oi.quantity,
                    (oi.quantity * oi.price) as revenue
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE oi.product_name = :product_name AND ($orderWhereClause)
                UNION ALL
                SELECT 
                    DATE(cs.created_at) as date,
                    csi.quantity,
                    (csi.quantity * csi.price) as revenue
                FROM credit_sale_items csi
                JOIN credit_sales cs ON csi.sale_id = cs.id
                WHERE csi.product_name = :product_name AND ($creditWhereClause)
            ) combined
            GROUP BY combined.date
            ORDER BY combined.date ASC
        ");
        $stmt->execute([':product_name' => $productName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getProductSalesData: " . $e->getMessage());
        return [];
    }
}

// Function to get total product sales
function getProductTotals($db, $productName, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT 
                SUM(combined.quantity) as total_quantity,
                SUM(combined.revenue) as total_revenue
            FROM (
                SELECT 
                    oi.quantity,
                    (oi.quantity * oi.price) as revenue
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE oi.product_name = :product_name AND ($orderWhereClause)
                UNION ALL
                SELECT 
                    csi.quantity,
                    (csi.quantity * csi.price) as revenue
                FROM credit_sale_items csi
                JOIN credit_sales cs ON csi.sale_id = cs.id
                WHERE csi.product_name = :product_name AND ($creditWhereClause)
            ) combined
        ");
        $stmt->execute([':product_name' => $productName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getProductTotals: " . $e->getMessage());
        return ['total_quantity' => 0, 'total_revenue' => 0];
    }
}

// Get sales data
$salesData = getProductSalesData($db, $productName, $startDate, $endDate, $closingTime, $isAfterMidnight);
$totals = getProductTotals($db, $productName, $startDate, $endDate, $closingTime, $isAfterMidnight);

// Create new PDF instance
$pdf = new ProductSalesPDF();
$pdf->productName = $productName;
$pdf->dateDisplay = $dateDisplay;
$pdf->AliasNbPages();
$pdf->AddPage();

// Set font
$pdf->SetFont('Arial', '', 12);

// Sales Data Table
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Daily Sales Breakdown', 0, 1, 'C');
$pdf->Ln(5);

// Table header
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(60, 8, 'Date', 1, 0, 'C');
$pdf->Cell(65, 8, 'Quantity Sold', 1, 0, 'C');
$pdf->Cell(65, 8, 'Sales Revenue', 1, 1, 'C');

// Table data
$pdf->SetFont('Arial', '', 9);
if (count($salesData) > 0) {
    foreach ($salesData as $sale) {
        // Check if we need a new page
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
            // Reprint table header on new page
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(60, 8, 'Date', 1, 0, 'C');
            $pdf->Cell(65, 8, 'Quantity Sold', 1, 0, 'C');
            $pdf->Cell(65, 8, 'Sales Revenue', 1, 1, 'C');
            $pdf->SetFont('Arial', '', 9);
        }
        
        $dateFormatted = date('M j, Y', strtotime($sale['date']));
        $quantity = $sale['total_quantity'] ?? 0;
        $revenue = $sale['total_revenue'] ?? 0;
        
        $pdf->Cell(60, 6, $dateFormatted, 1, 0, 'L');
        $pdf->Cell(65, 6, number_format($quantity), 1, 0, 'R');
        $pdf->Cell(65, 6, 'N$' . number_format($revenue, 2), 1, 1, 'R');
    }
} else {
    $pdf->Cell(190, 6, 'No sales data found for this period', 1, 1, 'C');
}

$pdf->Ln(5);

// Totals Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(60, 8, 'TOTALS:', 1, 0, 'L');
$pdf->Cell(65, 8, number_format($totals['total_quantity'] ?? 0), 1, 0, 'R');
$pdf->Cell(65, 8, 'N$' . number_format($totals['total_revenue'] ?? 0, 2), 1, 1, 'R');

$pdf->Ln(15);

// Summary Section
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Summary', 0, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(95, 8, 'Total Quantity Sold:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(95, 8, number_format($totals['total_quantity'] ?? 0), 0, 1, 'R');

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(95, 8, 'Total Sales Revenue:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(95, 8, 'N$' . number_format($totals['total_revenue'] ?? 0, 2), 0, 1, 'R');

$pdf->Ln(10);

// Add timestamp
$pdf->SetFont('Arial', 'I', 10);
$pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');

// Output PDF to browser for download
$safeProductName = preg_replace('/[^a-z0-9]/i', '_', $productName);
$filename = 'Product_Sales_' . $safeProductName . '_' . $startDate . '_to_' . $endDate . '.pdf';
$pdf->Output('D', $filename);
?>
