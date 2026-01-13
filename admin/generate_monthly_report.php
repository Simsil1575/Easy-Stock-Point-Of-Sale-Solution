<?php

session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Advanced PDF Library - Using FPDF for better features
require_once('../vendor/autoload.php');
require_once('../fpdf/fpdf.php');

// Get month and year from URL parameters
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month and year
if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
    die("Invalid month or year parameters");
}

// Connect to database
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    die("Connection failed: " . $db->errorInfo()[2]);
}

// Get the first and last day of the month
$firstDay = sprintf("%04d-%02d-01", $year, $month);
$lastDay = date('Y-m-t', strtotime($firstDay));

// Fetch monthly data
// Cash sales total
$cashSalesQuery = $db->prepare("SELECT SUM(total) FROM orders WHERE DATE(created_at) BETWEEN :firstDay AND :lastDay");
$cashSalesQuery->bindParam(':firstDay', $firstDay);
$cashSalesQuery->bindParam(':lastDay', $lastDay);
$cashSalesQuery->execute();
$cashSalesTotal = $cashSalesQuery->fetchColumn() ?: 0;

// Credit sales data
$creditSalesQuery = $db->prepare("SELECT 
    SUM(total_amount) as total_issued,
    SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE paid_amount END) as total_paid,
    SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount - paid_amount ELSE 0 END) as total_unpaid 
    FROM credit_sales WHERE DATE(created_at) BETWEEN :firstDay AND :lastDay");
$creditSalesQuery->bindParam(':firstDay', $firstDay);
$creditSalesQuery->bindParam(':lastDay', $lastDay);
$creditSalesQuery->execute();
$creditData = $creditSalesQuery->fetch(PDO::FETCH_ASSOC);
$creditTotal = $creditData['total_issued'] ?: 0;
$creditPaidTotal = $creditData['total_paid'] ?: 0;
$unpaidTotal = $creditData['total_unpaid'] ?: 0;

// Daily breakdown query
$dailyDataQuery = $db->prepare("
    SELECT DATE(created_at) as sale_date, 
           SUM(CASE WHEN source = 'cash' THEN amount ELSE 0 END) as cash_sales,
           SUM(CASE WHEN source = 'credit' THEN amount ELSE 0 END) as credit_sales,
           SUM(amount) as total_sales
    FROM (
        SELECT DATE(created_at) as created_at, total as amount, 'cash' as source FROM orders 
        WHERE DATE(created_at) BETWEEN :firstDay AND :lastDay
        
        UNION ALL
        
        SELECT DATE(created_at) as created_at, total_amount as amount, 'credit' as source FROM credit_sales
        WHERE DATE(created_at) BETWEEN :firstDay AND :lastDay
    )
    GROUP BY sale_date
    ORDER BY sale_date ASC
");
$dailyDataQuery->bindParam(':firstDay', $firstDay);
$dailyDataQuery->bindParam(':lastDay', $lastDay);
$dailyDataQuery->execute();
$dailyData = $dailyDataQuery->fetchAll(PDO::FETCH_ASSOC);

// Count total transactions
$transactionCountQuery = $db->prepare("
    SELECT COUNT(*) FROM (
        SELECT id FROM orders WHERE DATE(created_at) BETWEEN :firstDay AND :lastDay
        UNION ALL
        SELECT id FROM credit_sales WHERE DATE(created_at) BETWEEN :firstDay AND :lastDay
    )
");
$transactionCountQuery->bindParam(':firstDay', $firstDay);
$transactionCountQuery->bindParam(':lastDay', $lastDay);
$transactionCountQuery->execute();
$transactionCount = $transactionCountQuery->fetchColumn() ?: 0;

// Fetch EFT payments data for the month - Product Summary
$eftPaymentsQuery = $db->prepare("
    SELECT 
        t.product_name, 
        SUM(t.quantity) as total_qty, 
        SUM(t.price * t.quantity) as historical_value,
        p.price as current_price
    FROM (
        SELECT oi.product_name, oi.quantity, oi.price 
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN eft_payments ep ON o.id = ep.order_id
        WHERE DATE(o.created_at) BETWEEN :firstDay AND :lastDay
    ) t
    LEFT JOIN products p ON t.product_name = p.name
    GROUP BY t.product_name
    ORDER BY total_qty DESC
    LIMIT 10
");
$eftPaymentsQuery->bindParam(':firstDay', $firstDay);
$eftPaymentsQuery->bindParam(':lastDay', $lastDay);
$eftPaymentsQuery->execute();
$eftPayments = $eftPaymentsQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch cash transactions for the month
$cashTransactionsQuery = $db->prepare("
    SELECT * FROM cash_transactions 
    WHERE DATE(created_at) BETWEEN :firstDay AND :lastDay
    ORDER BY created_at DESC
");
$cashTransactionsQuery->bindParam(':firstDay', $firstDay);
$cashTransactionsQuery->bindParam(':lastDay', $lastDay);
$cashTransactionsQuery->execute();
$cashTransactions = $cashTransactionsQuery->fetchAll(PDO::FETCH_ASSOC);

// Calculate total EFT payments
$totalEftPayments = array_sum(array_column($eftPayments, 'historical_value'));

// Calculate total cash in/out
$totalCashIn = 0;
$totalCashOut = 0;
foreach ($cashTransactions as $transaction) {
    if ($transaction['type'] === 'cash-in') {
        $totalCashIn += $transaction['amount'];
    } else {
        $totalCashOut += $transaction['amount'];
    }
}

// Add a creditors section query
$creditorsQuery = $db->prepare("
    SELECT 
        creditors.id AS creditor_id,
        creditors.name AS creditor_name,
        creditors.phone AS phone,
        SUM(credit_sales.total_amount) as total_amount,
        SUM(credit_sales.paid_amount) as paid_amount,
        MAX(credit_sales.due_date) as latest_due_date,
        COUNT(*) as total_transactions
    FROM credit_sales 
    LEFT JOIN creditors ON credit_sales.creditor_id = creditors.id
    WHERE DATE(credit_sales.created_at) BETWEEN :firstDay AND :lastDay
    GROUP BY credit_sales.creditor_id
    ORDER BY total_amount - paid_amount DESC
");
$creditorsQuery->bindParam(':firstDay', $firstDay);
$creditorsQuery->bindParam(':lastDay', $lastDay);
$creditorsQuery->execute();
$creditors = $creditorsQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch business information from info.db
$businessInfo = array(
    'name' => 'Your Company Name',
    'location' => '',
    'phone' => '',
    'footer_text' => '',
    'logo' => '../logo.png'
);

try {
    $infoDb = new PDO('sqlite:../info.db');
    $infoQuery = $infoDb->query("SELECT * FROM business_info LIMIT 1");
    if ($infoQuery) {
        $businessData = $infoQuery->fetch(PDO::FETCH_ASSOC);
        if ($businessData) {
            $businessInfo['name'] = $businessData['name'] ?? $businessInfo['name'];
            $businessInfo['location'] = $businessData['location'] ?? $businessInfo['location'];
            $businessInfo['phone'] = $businessData['phone'] ?? $businessInfo['phone'];
            $businessInfo['footer_text'] = $businessData['footer_text'] ?? $businessInfo['footer_text'];
        }
    }
} catch (Exception $e) {
    // Use default values if info.db doesn't exist or has issues
}

// Advanced PDF Class with modern styling and features
class AdvancedMonthlyReportPDF extends FPDF {
    private $headerLogo = '../logo.png';
    private $companyName = 'Your Company Name';
    private $reportTitle = 'Monthly Sales Report';
    private $month;
    private $year;
    private $businessInfo;
    
    public function __construct($month, $year, $businessInfo = array()) {
        parent::__construct();
        $this->month = $month;
        $this->year = $year;
        $this->businessInfo = $businessInfo;
        $this->companyName = $businessInfo['name'] ?? 'Your Company Name';
        $this->headerLogo = $businessInfo['logo'] ?? '../logo.png';
        $this->SetAutoPageBreak(true, 15);
        $this->SetMargins(15, 15, 15);
    }
    
    function Header() {
        $monthName = date('F', mktime(0, 0, 0, $this->month, 1, $this->year));
        
        // Logo
        if (file_exists($this->headerLogo)) {
            $this->Image($this->headerLogo, 15, 10, 30);
        }
        
        // Company information
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, $this->companyName, 0, 1, 'C');
        
 
        
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, $this->reportTitle, 0, 1, 'C');
        
        $this->SetFont('Arial', '', 14);
        $this->SetTextColor(149, 165, 166);
        $this->Cell(0, 10, $monthName . ' ' . $this->year, 0, 1, 'C');
        
        // Add a decorative line
        $this->SetDrawColor(52, 152, 219);
        $this->SetLineWidth(0.5);
        $this->Line(15, $this->GetY() + 5, 195, $this->GetY() + 5);
        $this->Ln(15);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(149, 165, 166);
        $this->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s') . ' | Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    // Advanced styled table method
    function createStyledTable($headers, $data, $widths = null, $aligns = null, $colors = null) {
        // Default colors
        $headerColor = array(52, 73, 94);
        $rowColors = array(
            array(236, 240, 241), // Light gray
            array(255, 255, 255)  // White
        );
        
        if ($colors) {
            $headerColor = $colors['header'] ?? $headerColor;
            $rowColors = $colors['rows'] ?? $rowColors;
        }
        
        // Header
        $this->SetFillColor($headerColor[0], $headerColor[1], $headerColor[2]);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 11);
        $this->SetLineWidth(0.3);
        
        $w = $widths ?: array_fill(0, count($headers), 40);
        foreach($headers as $i => $header) {
            $align = $aligns && isset($aligns[$i]) ? $aligns[$i] : 'C';
            $this->Cell($w[$i], 8, $header, 1, 0, $align, 1);
        }
        $this->Ln();
        
        // Data rows
        $this->SetFont('Arial', '', 10);
        $fill = 0;
        foreach($data as $row) {
            $this->SetFillColor($rowColors[$fill][0], $rowColors[$fill][1], $rowColors[$fill][2]);
            $this->SetTextColor(0);
            
            foreach($row as $i => $cell) {
                $align = $aligns && isset($aligns[$i]) ? $aligns[$i] : 'L';
                $this->Cell($w[$i], 7, $cell, 1, 0, $align, 1);
            }
            $this->Ln();
            $fill = 1 - $fill; // Alternate colors
        }
        
        // Reset colors
        $this->SetFillColor(255, 255, 255);
        $this->SetTextColor(0);
        $this->Ln(5);
    }
    
    // Create summary boxes
    function createSummaryBox($title, $value, $color = array(52, 152, 219)) {
        $this->SetFillColor($color[0], $color[1], $color[2]);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 12);
        
        $this->Cell(0, 8, $title, 0, 1, 'L', 1);
        $this->SetFont('Arial', '', 14);
        $this->Cell(0, 10, $value, 0, 1, 'L', 1);
        $this->Ln(3);
    }
    
    // Create financial summary
    function createFinancialSummary($data) {
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, 'Financial Summary', 0, 1);
        $this->Ln(5);
        
        $this->SetFont('Arial', '', 11);
        foreach($data as $label => $value) {
            $this->Cell(80, 8, $label . ':', 0);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(0, 8, $value, 0, 1);
            $this->SetFont('Arial', '', 11);
        }
        $this->Ln(10);
    }
    
    // Create chart-like visualization
    function createChartData($data, $title) {
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, $title, 0, 1);
        $this->Ln(5);
        
        $this->SetFont('Arial', '', 10);
        foreach($data as $label => $value) {
            $this->Cell(60, 6, $label, 0);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(40, 6, $value, 0);
            $this->SetFont('Arial', '', 10);
            $this->Ln();
        }
        $this->Ln(10);
    }
    
    // Enhanced MultiCell with better formatting
    function MultiLineCell($width, $height, $text, $border=0, $align='L', $fill=false) {
        $x = $this->GetX();
        $y = $this->GetY();
        
        $this->MultiCell($width, 5, $text, 0, $align, $fill);
        
        $this->SetXY($x + $width, $y);
    }

    // Helper function to count number of lines
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if($c==' ') $sep = $i;
            $l += $cw[$c];
            if($l > $wmax) {
                if($sep==-1) {
                    if($i==$j) $i++;
                } else {
                    $i = $sep+1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}

// Create PDF instance
$pdf = new AdvancedMonthlyReportPDF($month, $year, $businessInfo);
$pdf->AliasNbPages();
$pdf->AddPage();

// Executive Summary with modern styling
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'Executive Summary', 0, 1);
$pdf->Ln(5);

// Create summary boxes in a grid layout
$pdf->createSummaryBox('Total Revenue', 'N$' . number_format($cashSalesTotal + $creditPaidTotal + $unpaidTotal, 2), array(46, 204, 113));
$pdf->createSummaryBox('Cash Sales', 'N$' . number_format($cashSalesTotal, 2), array(52, 152, 219));
$pdf->createSummaryBox('Credit Sales', 'N$' . number_format($creditTotal, 2), array(155, 89, 182));
$pdf->createSummaryBox('Total Transactions', number_format($transactionCount), array(230, 126, 34));

$pdf->AddPage();

// Daily breakdown with advanced table
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'Daily Sales Breakdown', 0, 1);
$pdf->Ln(5);

$headers = array('Date', 'Cash Sales', 'Credit Sales', 'Total');
$tableData = array();
foreach ($dailyData as $day) {
    $tableData[] = array(
        date('Y-m-d (D)', strtotime($day['sale_date'])),
        'N$' . number_format($day['cash_sales'], 2),
        'N$' . number_format($day['credit_sales'], 2),
        'N$' . number_format($day['total_sales'], 2)
    );
}

$pdf->createStyledTable($headers, $tableData, array(50, 45, 45, 45), array('L', 'R', 'R', 'R'));

// Top selling products
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'Top Selling Products', 0, 1);
$pdf->Ln(5);

$topProductsQuery = $db->prepare("
    SELECT 
        t.product_name, 
        SUM(t.quantity) as total_qty, 
        SUM(t.price * t.quantity) as historical_value,
        p.price as current_price
    FROM (
        SELECT product_name, quantity, price FROM order_items
        JOIN orders ON order_items.order_id = orders.id
        WHERE DATE(orders.created_at) BETWEEN :firstDay AND :lastDay
        
        UNION ALL
        
        SELECT product_name, quantity, price FROM credit_sale_items
        JOIN credit_sales ON credit_sale_items.sale_id = credit_sales.id
        WHERE DATE(credit_sales.created_at) BETWEEN :firstDay AND :lastDay
    ) t
    LEFT JOIN products p ON t.product_name = p.name
    GROUP BY t.product_name
    ORDER BY total_qty DESC
    LIMIT 10
");
$topProductsQuery->bindParam(':firstDay', $firstDay);
$topProductsQuery->bindParam(':lastDay', $lastDay);
$topProductsQuery->execute();
$topProducts = $topProductsQuery->fetchAll(PDO::FETCH_ASSOC);

$headers = array('Product', 'Quantity', 'Current Price', 'Total Value');
$tableData = array();
foreach ($topProducts as $product) {
    $totalValue = $product['current_price'] * $product['total_qty'];
    $tableData[] = array(
        $product['product_name'],
        number_format($product['total_qty']),
        'N$' . number_format($product['current_price'], 2),
        'N$' . number_format($totalValue, 2)
    );
}

$pdf->createStyledTable($headers, $tableData, array(70, 30, 45, 43), array('L', 'C', 'R', 'R'));

// Creditors section
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'Creditors Summary', 0, 1);
$pdf->Ln(5);

$headers = array('Name', 'Phone', 'Balance', 'Transactions');
$tableData = array();
foreach ($creditors as $creditor) {
    $tableData[] = array(
        $creditor['creditor_name'] ?: 'N/A',
        $creditor['phone'] ?: 'N/A',
        'N$' . number_format(($creditor['total_amount'] - $creditor['paid_amount']), 2),
        number_format($creditor['total_transactions'])
    );
}

$pdf->createStyledTable($headers, $tableData, array(50, 35, 35, 68), array('L', 'C', 'R', 'C'));

// EFT Payments section - Product Summary
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'EFT Payments - Product Summary', 0, 1);
$pdf->Ln(5);

$headers = array('Product', 'Quantity', 'Current Price', 'Total Value');
$tableData = array();
foreach ($eftPayments as $product) {
    $totalValue = $product['current_price'] * $product['total_qty'];
    $tableData[] = array(
        $product['product_name'],
        number_format($product['total_qty']),
        'N$' . number_format($product['current_price'], 2),
        'N$' . number_format($totalValue, 2)
    );
}

$pdf->createStyledTable($headers, $tableData, array(70, 30, 45, 43), array('L', 'C', 'R', 'R'));

// Cash Transactions section
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'Cash Transactions Summary', 0, 1);
$pdf->Ln(5);

$headers = array('Date', 'Type', 'Amount', 'Description');
$tableData = array();
foreach ($cashTransactions as $transaction) {
    $tableData[] = array(
        date('Y-m-d', strtotime($transaction['created_at'])),
        ucfirst($transaction['type']),
        'N$' . number_format($transaction['amount'], 2),
        $transaction['description'] ?: 'N/A'
    );
}

$pdf->createStyledTable($headers, $tableData, array(35, 30, 35, 88), array('C', 'C', 'R', 'L'));

// Financial summary page
$financialData = array(
    'Cash Sales Total' => 'N$' . number_format($cashSalesTotal, 2),
    'Credit Sales Total' => 'N$' . number_format($creditTotal, 2),
    'Paid Credit Amount' => 'N$' . number_format($creditPaidTotal, 2),
    'Unpaid Credit Amount' => 'N$' . number_format($unpaidTotal, 2),
    'Total EFT Payments' => 'N$' . number_format($totalEftPayments, 2),
    'Total Cash In' => 'N$' . number_format($totalCashIn, 2),
    'Total Cash Out' => 'N$' . number_format($totalCashOut, 2),
    'Net Cash Flow' => 'N$' . number_format($totalCashIn - $totalCashOut, 2)
);

$pdf->createFinancialSummary($financialData);

// Output the PDF
$fileName = 'Advanced_Monthly_Report_' . date('F_Y', mktime(0, 0, 0, $month, 1, $year)) . '.pdf';
$pdf->Output('D', $fileName);
?>
