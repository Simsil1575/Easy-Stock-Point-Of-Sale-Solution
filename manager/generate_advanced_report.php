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

// Advanced PDF Library - Using TCPDF for sophisticated features
require_once('../vendor/autoload.php');

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

// Fetch all the data (same as the original report)
// ... (data fetching code would go here, same as in generate_monthly_report.php)

// Advanced TCPDF Class with sophisticated features
class SophisticatedMonthlyReportPDF extends TCPDF {
    private $headerLogo = '../logo.png';
    private $companyName = 'Your Company Name';
    private $reportTitle = 'Advanced Monthly Sales Report';
    private $month;
    private $year;
    
    public function __construct($month, $year) {
        parent::__construct(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        $this->month = $month;
        $this->year = $year;
        
        // Set document information
        $this->SetCreator('Advanced POS System');
        $this->SetAuthor('Advanced POS System');
        $this->SetTitle('Advanced Monthly Sales Report');
        $this->SetSubject('Advanced Monthly Sales Report');
        $this->SetKeywords('sales, report, monthly, advanced');
        
        // Set default header data
        $this->SetHeaderData($this->headerLogo, 30, $this->companyName, $this->reportTitle);
        
        // Set header and footer fonts
        $this->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $this->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
        
        // Set default monospaced font
        $this->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        
        // Set margins
        $this->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $this->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->SetFooterMargin(PDF_MARGIN_FOOTER);
        
        // Set auto page breaks
        $this->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        
        // Set image scale factor
        $this->setImageScale(PDF_IMAGE_SCALE_RATIO);
        
        // Set default font subsetting
        $this->setFontSubsetting(true);
        
        // Set font
        $this->SetFont('helvetica', '', 10);
    }
    
    public function Header() {
        $monthName = date('F', mktime(0, 0, 0, $this->month, 1, $this->year));
        
        // Logo
        if (file_exists($this->headerLogo)) {
            $this->Image($this->headerLogo, 10, 10, 30);
        }
        
        // Set font
        $this->SetFont('helvetica', 'B', 20);
        
        // Title with gradient effect
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 15, $this->reportTitle, 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln();
        
        // Subtitle
        $this->SetFont('helvetica', '', 14);
        $this->SetTextColor(149, 165, 166);
        $this->Cell(0, 10, $monthName . ' ' . $this->year, 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(20);
        
        // Add decorative line
        $this->SetDrawColor(52, 152, 219);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(10);
    }
    
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('helvetica', 'I', 8);
        // Page number
        $this->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s') . ' | Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
    
    // Advanced styled table with gradients
    public function createAdvancedTable($headers, $data, $widths = null, $aligns = null) {
        // Colors, line width and bold font
        $this->SetFillColor(52, 73, 94);
        $this->SetTextColor(255);
        $this->SetDrawColor(52, 73, 94);
        $this->SetLineWidth(0.3);
        $this->SetFont('helvetica', 'B', 11);
        
        // Header
        $w = $widths ?: array_fill(0, count($headers), 40);
        foreach($headers as $i => $header) {
            $align = $aligns && isset($aligns[$i]) ? $aligns[$i] : 'C';
            $this->Cell($w[$i], 7, $header, 1, 0, $align, 1);
        }
        $this->Ln();
        
        // Color and font restoration
        $this->SetFillColor(245, 245, 245);
        $this->SetTextColor(0);
        $this->SetFont('helvetica', '', 10);
        
        // Data with alternating row colors
        $fill = 0;
        foreach($data as $row) {
            $this->SetFillColor($fill ? 255 : 245, $fill ? 255 : 245, $fill ? 255 : 245);
            foreach($row as $i => $cell) {
                $align = $aligns && isset($aligns[$i]) ? $aligns[$i] : 'L';
                $this->Cell($w[$i], 6, $cell, 'LR', 0, $align, 1);
            }
            $this->Ln();
            $fill = !$fill;
        }
        
        // Closing line
        $this->Cell(array_sum($w), 0, '', 'T');
        $this->Ln(10);
    }
    
    // Create summary boxes with gradients
    public function createSummaryBox($title, $value, $color = array(52, 152, 219)) {
        $this->SetFillColor($color[0], $color[1], $color[2]);
        $this->SetTextColor(255);
        $this->SetFont('helvetica', 'B', 12);
        
        $this->Cell(0, 8, $title, 0, 1, 'L', 1);
        $this->SetFont('helvetica', '', 14);
        $this->Cell(0, 10, $value, 0, 1, 'L', 1);
        $this->Ln(5);
    }
    
    // Create chart visualization
    public function createChartVisualization($data, $title) {
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, $title, 0, 1);
        $this->Ln(5);
        
        $this->SetFont('helvetica', '', 10);
        foreach($data as $label => $value) {
            $this->Cell(60, 6, $label, 0);
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(40, 6, $value, 0);
            $this->SetFont('helvetica', '', 10);
            $this->Ln();
        }
        $this->Ln(10);
    }
    
    // Create financial summary with styling
    public function createFinancialSummary($data) {
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, 'Financial Summary', 0, 1);
        $this->Ln(5);
        
        $this->SetFont('helvetica', '', 11);
        foreach($data as $label => $value) {
            $this->Cell(80, 8, $label . ':', 0);
            $this->SetFont('helvetica', 'B', 11);
            $this->Cell(0, 8, $value, 0, 1);
            $this->SetFont('helvetica', '', 11);
        }
        $this->Ln(10);
    }
}

// Create PDF instance
$pdf = new SophisticatedMonthlyReportPDF($month, $year);

// Add a page
$pdf->AddPage();

// Executive Summary with advanced styling
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'Executive Summary', 0, 1);
$pdf->Ln(5);

// Create summary boxes
$pdf->createSummaryBox('Total Revenue', 'N$' . number_format($cashSalesTotal + $creditPaidTotal + $unpaidTotal, 2), array(46, 204, 113));
$pdf->createSummaryBox('Cash Sales', 'N$' . number_format($cashSalesTotal, 2), array(52, 152, 219));
$pdf->createSummaryBox('Credit Sales', 'N$' . number_format($creditTotal, 2), array(155, 89, 182));
$pdf->createSummaryBox('Total Transactions', number_format($transactionCount), array(230, 126, 34));

$pdf->AddPage();

// Daily breakdown with advanced table
$pdf->SetFont('helvetica', 'B', 16);
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

$pdf->createAdvancedTable($headers, $tableData, array(50, 45, 45, 45), array('L', 'R', 'R', 'R'));

// Financial summary page
$pdf->AddPage();
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
$fileName = 'Sophisticated_Monthly_Report_' . date('F_Y', mktime(0, 0, 0, $month, 1, $year)) . '.pdf';
$pdf->Output($fileName, 'D');
?> 