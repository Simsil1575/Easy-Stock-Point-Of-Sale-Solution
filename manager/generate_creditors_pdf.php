<?php
require('../fpdf/fpdf.php');

// Set timezone
date_default_timezone_set('Africa/Harare');

// Connect to the database
$db = new PDO('sqlite:../pos.db');

// Fetch business information
$businessInfo = array(
    'name' => 'Your Business Name',
    'location' => 'Business Location',
    'phone' => 'Business Phone',
    'email' => 'business@email.com'
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
            $businessInfo['email'] = $businessData['email'] ?? $businessInfo['email'];
        }
    }
} catch (Exception $e) {
    // Use default values if info.db doesn't exist
}

// Fetch all creditors with detailed information
$creditors = $db->query("
    SELECT 
        c.id,
        c.name,
        c.phone,
        c.active,
        COALESCE(SUM(cs.total_amount), 0) as total_issued,
        COALESCE(SUM(cs.paid_amount), 0) as total_paid,
        COALESCE(SUM(cs.total_amount - cs.paid_amount), 0) as outstanding_balance,
        COUNT(cs.id) as total_transactions,
        MAX(cs.created_at) as last_transaction_date
    FROM creditors c
    LEFT JOIN credit_sales cs ON c.id = cs.creditor_id
    GROUP BY c.id, c.name, c.phone, c.active
    ORDER BY outstanding_balance DESC, c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch detailed transaction information for each creditor
$detailedTransactions = [];
foreach ($creditors as $creditor) {
    $stmt = $db->prepare("
        SELECT 
            cs.id,
            cs.total_amount,
            cs.paid_amount,
            cs.payment_status,
            cs.created_at,
            cs.due_date,
            GROUP_CONCAT(csi.product_name || ' (Qty: ' || csi.quantity || ' @ N$' || csi.price || ')', ' | ') as items
        FROM credit_sales cs
        LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
        WHERE cs.creditor_id = ?
        GROUP BY cs.id
        ORDER BY cs.created_at DESC
    ");
    $stmt->execute([$creditor['id']]);
    $detailedTransactions[$creditor['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$totalCreditors = count($creditors);
$totalIssued = array_sum(array_column($creditors, 'total_issued'));
$totalPaid = array_sum(array_column($creditors, 'total_paid'));
$totalOutstanding = array_sum(array_column($creditors, 'outstanding_balance'));
$activeCreditors = count(array_filter($creditors, function($c) { return $c['active'] == 1; }));
$unpaidCreditors = count(array_filter($creditors, function($c) { return $c['outstanding_balance'] > 0; }));

class ProfessionalCreditorsPDF extends FPDF {
    private $businessInfo;
    
    public function __construct($businessInfo) {
        parent::__construct();
        $this->businessInfo = $businessInfo;
    }
    
    function Header() {
        // Company header
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, $this->businessInfo['name'], 0, 1, 'C');
        
        $this->SetFont('Arial', '', 12);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 6, $this->businessInfo['location'], 0, 1, 'C');
        $this->Cell(0, 6, 'Phone: ' . $this->businessInfo['phone'], 0, 1, 'C');
        
        // Report title
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, 'CREDITORS REPORT', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 6, 'Generated on: ' . date('F d, Y \a\t g:i A'), 0, 1, 'C');
        $this->Cell(0, 6, 'Report Period: All Time', 0, 1, 'C');
        
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->Cell(0, 10, $this->businessInfo['name'] . ' - Creditors Report', 0, 0, 'R');
    }
    
    function createSummaryBox($title, $value, $color = array(52, 152, 219)) {
        $this->SetFillColor($color[0], $color[1], $color[2]);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(47, 15, $title, 1, 0, 'C', true);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(47, 15, $value, 1, 1, 'C', true);
    }
    
    function createCreditorTable($creditors) {
        // Table header
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetFillColor(52, 73, 94);
        
        $this->Cell(15, 8, 'ID', 1, 0, 'C', true);
        $this->Cell(45, 8, 'Creditor Name', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Phone', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Status', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Total Issued', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Total Paid', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Balance', 1, 1, 'C', true);
        
        // Table data
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(52, 73, 94);
        $this->SetFillColor(248, 249, 250);
        
        $rowCount = 0;
        foreach ($creditors as $creditor) {
            $rowCount++;
            $fill = ($rowCount % 2 == 0);
            
            $this->Cell(15, 7, $creditor['id'], 1, 0, 'C', $fill);
            $this->Cell(45, 7, substr($creditor['name'], 0, 20), 1, 0, 'L', $fill);
            $this->Cell(25, 7, $creditor['phone'] ?: 'N/A', 1, 0, 'C', $fill);
            
            // Status with color coding
            if ($creditor['outstanding_balance'] > 0) {
                $this->SetTextColor(220, 53, 69); // Red for unpaid
                $status = 'UNPAID';
            } elseif ($creditor['total_issued'] > 0) {
                $this->SetTextColor(40, 167, 69); // teal for paid
                $status = 'PAID';
            } else {
                $this->SetTextColor(108, 117, 125); // Gray for new
                $status = 'NEW';
            }
            $this->Cell(25, 7, $status, 1, 0, 'C', $fill);
            $this->SetTextColor(52, 73, 94);
            
            $this->Cell(30, 7, 'N$' . number_format($creditor['total_issued'], 2), 1, 0, 'R', $fill);
            $this->Cell(30, 7, 'N$' . number_format($creditor['total_paid'], 2), 1, 0, 'R', $fill);
            
            // Balance with color coding
            if ($creditor['outstanding_balance'] > 0) {
                $this->SetTextColor(220, 53, 69);
            } else {
                $this->SetTextColor(40, 167, 69);
            }
            $this->Cell(30, 7, 'N$' . number_format($creditor['outstanding_balance'], 2), 1, 1, 'R', $fill);
            $this->SetTextColor(52, 73, 94);
        }
    }
    
    function createDetailedTransactions($creditor, $transactions) {
        if (empty($transactions)) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(128, 128, 128);
            $this->Cell(0, 8, 'No transactions found for this creditor.', 0, 1, 'C');
            return;
        }
        
        // Transaction header
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetFillColor(52, 152, 219);
        
        $this->Cell(25, 8, 'Date', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Amount', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Paid', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Status', 1, 0, 'C', true);
        $this->Cell(80, 8, 'Items', 1, 1, 'C', true);
        
        // Transaction data
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(52, 73, 94);
        $this->SetFillColor(248, 249, 250);
        
        $rowCount = 0;
        foreach ($transactions as $transaction) {
            $rowCount++;
            $fill = ($rowCount % 2 == 0);
            
            $this->Cell(25, 6, date('Y-m-d', strtotime($transaction['created_at'])), 1, 0, 'C', $fill);
            $this->Cell(30, 6, 'N$' . number_format($transaction['total_amount'], 2), 1, 0, 'R', $fill);
            $this->Cell(30, 6, 'N$' . number_format($transaction['paid_amount'], 2), 1, 0, 'R', $fill);
            
            // Status
            if ($transaction['payment_status'] == 'paid') {
                $this->SetTextColor(40, 167, 69);
                $status = 'PAID';
            } else {
                $this->SetTextColor(220, 53, 69);
                $status = 'UNPAID';
            }
            $this->Cell(25, 6, $status, 1, 0, 'C', $fill);
            $this->SetTextColor(52, 73, 94);
            
            // Items (truncated if too long)
            $items = substr($transaction['items'], 0, 50);
            if (strlen($transaction['items']) > 50) {
                $items .= '...';
            }
            $this->Cell(80, 6, $items, 1, 1, 'L', $fill);
        }
    }
    
    function createTotalsSection($totals) {
        $this->Ln(10);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 10, 'SUMMARY TOTALS', 0, 1, 'C');
        $this->Ln(5);
        
        // Create totals table
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetFillColor(52, 73, 94);
        
        $this->Cell(80, 10, 'Description', 1, 0, 'C', true);
        $this->Cell(40, 10, 'Count', 1, 0, 'C', true);
        $this->Cell(70, 10, 'Amount (N$)', 1, 1, 'C', true);
        
        // Totals data
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(52, 73, 94);
        $this->SetFillColor(248, 249, 250);
        
        $this->Cell(80, 8, 'Total Creditors', 1, 0, 'L', true);
        $this->Cell(40, 8, number_format($totals['total_creditors']), 1, 0, 'C', true);
        $this->Cell(70, 8, '-', 1, 1, 'C', true);
        
        $this->Cell(80, 8, 'Active Creditors', 1, 0, 'L', false);
        $this->Cell(40, 8, number_format($totals['active_creditors']), 1, 0, 'C', false);
        $this->Cell(70, 8, '-', 1, 1, 'C', false);
        
        $this->Cell(80, 8, 'Creditors with Outstanding Balance', 1, 0, 'L', true);
        $this->Cell(40, 8, number_format($totals['unpaid_creditors']), 1, 0, 'C', true);
        $this->Cell(70, 8, '-', 1, 1, 'C', true);
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 8, 'Total Credit Issued', 1, 0, 'L', false);
        $this->Cell(40, 8, '-', 1, 0, 'C', false);
        $this->Cell(70, 8, 'N$' . number_format($totals['total_issued'], 2), 1, 1, 'R', false);
        
        $this->Cell(80, 8, 'Total Credit Paid', 1, 0, 'L', true);
        $this->Cell(40, 8, '-', 1, 0, 'C', true);
        $this->Cell(70, 8, 'N$' . number_format($totals['total_paid'], 2), 1, 1, 'R', true);
        
        $this->SetFillColor(255, 193, 7);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(80, 10, 'TOTAL OUTSTANDING BALANCE', 1, 0, 'L', true);
        $this->Cell(40, 10, '-', 1, 0, 'C', true);
        $this->Cell(70, 10, 'N$' . number_format($totals['total_outstanding'], 2), 1, 1, 'R', true);
    }
}

// Create PDF instance
$pdf = new ProfessionalCreditorsPDF($businessInfo);
$pdf->AliasNbPages();
$pdf->AddPage();

// Executive Summary
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'EXECUTIVE SUMMARY', 0, 1, 'C');
$pdf->Ln(5);

// Summary boxes
$pdf->createSummaryBox('Total Creditors', number_format($totalCreditors), array(52, 152, 219));
$pdf->createSummaryBox('Active Creditors', number_format($activeCreditors), array(40, 167, 69));
$pdf->createSummaryBox('Unpaid Creditors', number_format($unpaidCreditors), array(220, 53, 69));
$pdf->createSummaryBox('Total Outstanding', 'N$' . number_format($totalOutstanding, 2), array(255, 193, 7));

$pdf->AddPage();

// Creditors Table
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'CREDITORS OVERVIEW', 0, 1, 'C');
$pdf->Ln(5);

$pdf->createCreditorTable($creditors);

// Detailed transactions for each creditor (if space allows)
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 10, 'DETAILED TRANSACTIONS', 0, 1, 'C');
$pdf->Ln(5);

foreach ($creditors as $creditor) {
    if ($creditor['total_transactions'] > 0) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(52, 73, 94);
        $pdf->Cell(0, 8, 'Creditor: ' . $creditor['name'] . ' (ID: ' . $creditor['id'] . ')', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 6, 'Phone: ' . ($creditor['phone'] ?: 'N/A') . ' | Balance: N$' . number_format($creditor['outstanding_balance'], 2), 0, 1, 'L');
        $pdf->Ln(3);
        
        $pdf->createDetailedTransactions($creditor, $detailedTransactions[$creditor['id']]);
        $pdf->Ln(10);
        
        // Check if we need a new page
        if ($pdf->GetY() > 200) {
            $pdf->AddPage();
        }
    }
}

// Totals section
$pdf->AddPage();
$totals = array(
    'total_creditors' => $totalCreditors,
    'active_creditors' => $activeCreditors,
    'unpaid_creditors' => $unpaidCreditors,
    'total_issued' => $totalIssued,
    'total_paid' => $totalPaid,
    'total_outstanding' => $totalOutstanding
);

$pdf->createTotalsSection($totals);

// Additional notes
$pdf->Ln(15);
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetTextColor(128, 128, 128);
$pdf->Cell(0, 6, 'Notes:', 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, '• This report includes all creditors and their credit transactions.', 0, 1, 'L');
$pdf->Cell(0, 5, '• Outstanding balances are calculated as total issued minus total paid.', 0, 1, 'L');
$pdf->Cell(0, 5, '• Status is determined by current outstanding balance.', 0, 1, 'L');
$pdf->Cell(0, 5, '• Report generated on ' . date('F d, Y \a\t g:i A') . '.', 0, 1, 'L');

// Output the PDF
$fileName = 'Creditors_Report_' . date('Y-m-d_H-i-s') . '.pdf';
$pdf->Output('D', $fileName);
?> 