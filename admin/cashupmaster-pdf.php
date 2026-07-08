<?php
// cashupmaster-pdf.php - Generate PDF cash-up master report using FPDF
// Uses the same calculation logic as admin/cashupmaster.php

require('../fpdf/fpdf.php');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Check if user has the correct role (admin only)
if (strtolower($_SESSION['role']) !== 'admin') {
    session_unset();
    session_destroy();
    header("Location: ../");
    exit();
}

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $infoDb = new PDO('sqlite:../info.db');
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get business info
$businessInfo = [];
$closingTime = '22:00';
try {
    $businessInfo = $infoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '22:00';
} catch (PDOException $e) {
    $closingTime = '22:00';
}

// Get date from POST or GET
$selectedDate = isset($_POST['date']) ? $_POST['date'] : (isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'));
$nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Calculate business day boundaries
$closingHour = (int)substr($closingTime, 0, 2);
$isAfterMidnight = $closingHour < 12;

/**
 * Helper function to get business day WHERE clause
 */
function getBusinessDayWhere($dateField) {
    global $selectedDate, $nextBusinessDay, $closingTime, $isAfterMidnight;
    return "
        (DATE($dateField) = :selectedDate AND strftime('%H:%M', $dateField) >= :closingTime) OR
        (DATE($dateField) = :nextBusinessDay AND strftime('%H:%M', $dateField) < :closingTime AND :isAfterMidnight = 1)
    ";
}

// Calculate all amounts using same logic as cashupmaster.php
$eftTableExists = false;
try {
    $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
    $eftTableExists = ($checkEftTable->fetchColumn() !== false);
} catch (PDOException $e) {
    $eftTableExists = false;
}

if ($eftTableExists) {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(
            o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
        ), 0)
        FROM orders o
        WHERE " . getBusinessDayWhere('o.created_at') . "
    ");
} else {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE " . getBusinessDayWhere('created_at') . "
    ");
}
$cashSalesQuery->bindParam(':selectedDate', $selectedDate);
$cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashSalesQuery->bindParam(':closingTime', $closingTime);
$cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashSalesQuery->execute();
$totalCashSales = $cashSalesQuery->fetchColumn();

$cashInQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-in' AND " . getBusinessDayWhere('created_at') . "
");
$cashInQuery->bindParam(':selectedDate', $selectedDate);
$cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashInQuery->bindParam(':closingTime', $closingTime);
$cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashInQuery->execute();
$totalCashIn = $cashInQuery->fetchColumn();

$creditPaymentsQuery = $db->prepare("
    SELECT COALESCE(SUM(p.amount), 0) 
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE cs.payment_status = 'paid' AND " . getBusinessDayWhere('p.payment_date') . "
");
$creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
$creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$creditPaymentsQuery->bindParam(':closingTime', $closingTime);
$creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$creditPaymentsQuery->execute();
$totalCreditPayments = $creditPaymentsQuery->fetchColumn();

$cashOutQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-out' AND " . getBusinessDayWhere('created_at') . "
");
$cashOutQuery->bindParam(':selectedDate', $selectedDate);
$cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashOutQuery->bindParam(':closingTime', $closingTime);
$cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashOutQuery->execute();
$totalCashOut = $cashOutQuery->fetchColumn();

$cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
$cashSalesExpected = $cashInTill;

$cardSalesExpected = 0;
if ($eftTableExists) {
    $cardSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(ep.amount), 0)
        FROM eft_payments ep
        JOIN orders o ON ep.order_id = o.id
        WHERE " . getBusinessDayWhere('ep.payment_date') . "
    ");
    $cardSalesQuery->bindParam(':selectedDate', $selectedDate);
    $cardSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
    $cardSalesQuery->bindParam(':closingTime', $closingTime);
    $cardSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
    $cardSalesQuery->execute();
    $cardSalesExpected = $cardSalesQuery->fetchColumn();
}

$unpaidCreditSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(total_amount - paid_amount), 0)
    FROM credit_sales
    WHERE payment_status = 'unpaid' AND " . getBusinessDayWhere('created_at') . "
");
$unpaidCreditSalesQuery->bindParam(':selectedDate', $selectedDate);
$unpaidCreditSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$unpaidCreditSalesQuery->bindParam(':closingTime', $closingTime);
$unpaidCreditSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$unpaidCreditSalesQuery->execute();
$unpaidCreditSales = $unpaidCreditSalesQuery->fetchColumn();

$openTabsQuery = $db->prepare("
    SELECT COALESCE(SUM(current_balance), 0)
    FROM tabs
    WHERE status = 'open' AND " . getBusinessDayWhere('opened_at') . "
");
$openTabsQuery->bindParam(':selectedDate', $selectedDate);
$openTabsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$openTabsQuery->bindParam(':closingTime', $closingTime);
$openTabsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$openTabsQuery->execute();
$openTabsBalance = $openTabsQuery->fetchColumn();

$creditReturnsQuery = $db->prepare("
    SELECT COALESCE(SUM(return_amount), 0)
    FROM credit_returns
    WHERE " . getBusinessDayWhere('created_at') . "
");
$creditReturnsQuery->bindParam(':selectedDate', $selectedDate);
$creditReturnsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$creditReturnsQuery->bindParam(':closingTime', $closingTime);
$creditReturnsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$creditReturnsQuery->execute();
$creditReturnsAmount = $creditReturnsQuery->fetchColumn();
$creditReturns = $creditReturnsAmount + $totalCreditPayments;

$expensesQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM cash_transactions
    WHERE type = 'cash-out' 
    AND (description NOT LIKE '%Tips%' AND description NOT LIKE '%Cash Back%' AND description NOT LIKE '%tip%' AND description NOT LIKE '%cash back%')
    AND " . getBusinessDayWhere('created_at') . "
");
$expensesQuery->bindParam(':selectedDate', $selectedDate);
$expensesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$expensesQuery->bindParam(':closingTime', $closingTime);
$expensesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$expensesQuery->execute();
$expenses = $expensesQuery->fetchColumn();

$cashBackQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM cash_transactions
    WHERE type = 'cash-out' 
    AND (description LIKE '%Cash Back%' OR description LIKE '%cash back%')
    AND " . getBusinessDayWhere('created_at') . "
");
$cashBackQuery->bindParam(':selectedDate', $selectedDate);
$cashBackQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashBackQuery->bindParam(':closingTime', $closingTime);
$cashBackQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashBackQuery->execute();
$cashBackSystem = $cashBackQuery->fetchColumn();

$voidsQuery = $db->prepare("
    SELECT COALESCE(SUM(total), 0)
    FROM void_transactions
    WHERE " . getBusinessDayWhere('voided_at') . "
");
$voidsQuery->bindParam(':selectedDate', $selectedDate);
$voidsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$voidsQuery->bindParam(':closingTime', $closingTime);
$voidsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$voidsQuery->execute();
$voids = $voidsQuery->fetchColumn();

$refundsQuery = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM refunds
    WHERE " . getBusinessDayWhere('created_at') . "
");
$refundsQuery->bindParam(':selectedDate', $selectedDate);
$refundsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$refundsQuery->bindParam(':closingTime', $closingTime);
$refundsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$refundsQuery->execute();
$refunds = $refundsQuery->fetchColumn();

$totalItemsSoldQuery = $db->prepare("
    SELECT COALESCE(SUM(total), 0)
    FROM orders
    WHERE " . getBusinessDayWhere('created_at') . "
");
$totalItemsSoldQuery->bindParam(':selectedDate', $selectedDate);
$totalItemsSoldQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$totalItemsSoldQuery->bindParam(':closingTime', $closingTime);
$totalItemsSoldQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$totalItemsSoldQuery->execute();
$totalItemsSold = $totalItemsSoldQuery->fetchColumn() ?? 0;

// Get user-entered values from POST
$cashOnHand = floatval($_POST['cash_on_hand'] ?? 0);
$cashBack = isset($_POST['cash_back']) ? floatval($_POST['cash_back']) : $cashBackSystem;
$overShort = $cashOnHand - $cashSalesExpected;

// PDF Class
class CashupMasterPDF extends FPDF {
    private $businessName;
    private $location;
    private $phone;
    
    function __construct($businessName, $location, $phone) {
        parent::__construct();
        $this->businessName = $businessName;
        $this->location = $location;
        $this->phone = $phone;
    }
    
    function Header() {
        // Business name
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, $this->businessName, 0, 1, 'C');
        
        // Location
        if (!empty($this->location)) {
            $this->SetFont('Arial', '', 12);
            $this->Cell(0, 8, $this->location, 0, 1, 'C');
        }
        
        // Phone
        if (!empty($this->phone)) {
            $this->SetFont('Arial', '', 12);
            $this->Cell(0, 8, 'Tel: ' . $this->phone, 0, 1, 'C');
        }
        
        // Title
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'CASH UP REPORT', 0, 1, 'C');
        
        // Date and time
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'Date: ' . ($_POST['date'] ?? date('Y-m-d')), 0, 1, 'C');
        $this->Cell(0, 6, 'Time: ' . date('H:i'), 0, 1, 'C');
        $this->Cell(0, 6, 'Cashier: ' . ($_SESSION['username'] ?? 'Admin'), 0, 1, 'C');
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, '*End of day cash up*', 0, 0, 'C');
    }
    
    function formatLine($label, $amount) {
        $this->SetFont('Arial', '', 11);
        $amountStr = 'N$ ' . number_format(floatval($amount), 2);
        $this->Cell(120, 8, $label, 0, 0, 'L');
        $this->Cell(0, 8, $amountStr, 0, 1, 'R');
    }
}

// Create PDF
$pdf = new CashupMasterPDF(
    $businessInfo['name'] ?? 'POS SOLUTION',
    $businessInfo['location'] ?? '',
    $businessInfo['phone'] ?? ''
);
$pdf->AddPage();

// CASH SECTION
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'CASH', 0, 1, 'C');
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);
$pdf->formatLine('Cash Sales (Expected)', $cashSalesExpected);
$pdf->formatLine('Cash on Hand', $cashOnHand);
$pdf->formatLine('Over / Short', $overShort);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(5);

// CARD & CREDIT SECTION
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'CARD & CREDIT', 0, 1, 'C');
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);
$pdf->formatLine('Card Sales (Expected)', $cardSalesExpected);
$pdf->formatLine('Unpaid Credit Sales', $unpaidCreditSales);
$pdf->formatLine('Open Tabs Balance', $openTabsBalance);
$pdf->formatLine('Credit Returns', $creditReturns);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(5);

// DEDUCTIONS SECTION
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'DEDUCTIONS', 0, 1, 'C');
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);
$pdf->formatLine('Expenses', $expenses);
$pdf->formatLine('Cash Back', $cashBack);
$pdf->formatLine('Voids', $voids);
$pdf->formatLine('Refunds', $refunds);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(5);

// TOTAL VALUE OF ITEMS SOLD SECTION
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'TOTAL VALUE OF ITEMS SOLD', 0, 1, 'C');
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(3);
$pdf->formatLine('Total Value of Items Sold', $totalItemsSold);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());

// Output PDF
$filename = 'Cashup_Master_' . $selectedDate . '.pdf';
$pdf->Output('D', $filename);
exit;
?>
