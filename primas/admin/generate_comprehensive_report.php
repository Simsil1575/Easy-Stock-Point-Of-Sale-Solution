<?php
// Check if user is logged in and has admin privileges
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Require the FPDF library
require('../fpdf/fpdf.php');

// Database connection
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

try {
    // Start transaction
    $db->beginTransaction();

    // Generate comprehensive report
    $reportData = [];

    // Get all orders
    $ordersQuery = $db->query("SELECT * FROM orders ORDER BY created_at ASC");
    $reportData['orders'] = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);

    // Get all credit sales
    $creditSalesQuery = $db->query("SELECT * FROM credit_sales ORDER BY created_at ASC");
    $reportData['credit_sales'] = $creditSalesQuery->fetchAll(PDO::FETCH_ASSOC);

    // Get all cash transactions
    $cashTransactionsQuery = $db->query("SELECT * FROM cash_transactions ORDER BY created_at ASC");
    $reportData['cash_transactions'] = $cashTransactionsQuery->fetchAll(PDO::FETCH_ASSOC);

    // Get all EFT payments
    $eftPaymentsQuery = $db->query("SELECT * FROM eft_payments ORDER BY created_at ASC");
    $reportData['eft_payments'] = $eftPaymentsQuery->fetchAll(PDO::FETCH_ASSOC);

    // Create report directory if it doesn't exist
    $reportDir = '../reports/comprehensive';
    if (!file_exists($reportDir)) {
        mkdir($reportDir, 0777, true);
    }

    // Generate report filename with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $reportFile = $reportDir . '/comprehensive_report_' . $timestamp . '.json';

    // Save report to file
    file_put_contents($reportFile, json_encode($reportData, JSON_PRETTY_PRINT));

    // Reset the system
    // Clear orders table
    $db->exec("DELETE FROM orders");
    $db->exec("DELETE FROM order_items");

    // Clear credit sales table
    $db->exec("DELETE FROM credit_sales");
    $db->exec("DELETE FROM credit_sale_items");

    // Clear cash transactions table
    $db->exec("DELETE FROM cash_transactions");

    // Clear EFT payments table
    $db->exec("DELETE FROM eft_payments");

    // Commit transaction
    $db->commit();

    // Return success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Comprehensive report generated and system reset successfully',
        'report_file' => $reportFile
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

// Create a new PDF instance
class ComprehensiveReportPDF extends FPDF {
    function Header() {
        // Logo
        $this->Image('../logo.png', 10, 10, 30);
        // Company information
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 10, 'Comprehensive Sales Report', 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 10, 'All Transactions Up To ' . date('F d, Y'), 0, 1, 'C');
        $this->Ln(10);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

$pdf = new ComprehensiveReportPDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Summary section
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Summary', 0, 1);

// Get all-time totals
$cashSalesQuery = $db->query("SELECT SUM(total) FROM orders");
$cashSalesTotal = $cashSalesQuery->fetchColumn() ?: 0;

$creditSalesQuery = $db->query("SELECT 
    SUM(total_amount) as total_issued,
    SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE paid_amount END) as total_paid,
    SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount - paid_amount ELSE 0 END) as total_unpaid 
    FROM credit_sales");
$creditData = $creditSalesQuery->fetch(PDO::FETCH_ASSOC);
$creditTotal = $creditData['total_issued'] ?: 0;
$creditPaidTotal = $creditData['total_paid'] ?: 0;
$unpaidTotal = $creditData['total_unpaid'] ?: 0;

$eftPaymentsQuery = $db->query("SELECT SUM(amount) FROM eft_payments");
$totalEftPayments = $eftPaymentsQuery->fetchColumn() ?: 0;

$cashInQuery = $db->query("SELECT SUM(amount) FROM cash_transactions WHERE type = 'cash-in'");
$totalCashIn = $cashInQuery->fetchColumn() ?: 0;

$cashOutQuery = $db->query("SELECT SUM(amount) FROM cash_transactions WHERE type = 'cash-out'");
$totalCashOut = $cashOutQuery->fetchColumn() ?: 0;

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(80, 10, 'Total Cash Sales:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($cashSalesTotal, 2), 0, 1);

$pdf->Cell(80, 10, 'Total Credit Sales:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($creditTotal, 2), 0, 1);

$pdf->Cell(80, 10, 'Total Credit Paid:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($creditPaidTotal, 2), 0, 1);

$pdf->Cell(80, 10, 'Total Credit Unpaid:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($unpaidTotal, 2), 0, 1);

$pdf->Cell(80, 10, 'Total EFT Payments:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($totalEftPayments, 2), 0, 1);

$pdf->Cell(80, 10, 'Total Cash In:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($totalCashIn, 2), 0, 1);

$pdf->Cell(80, 10, 'Total Cash Out:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($totalCashOut, 2), 0, 1);

$pdf->Cell(80, 10, 'Total Revenue:', 0);
$pdf->Cell(0, 10, 'N$' . number_format($cashSalesTotal + $creditPaidTotal + $unpaidTotal, 2), 0, 1);

$pdf->Ln(10);

// Daily breakdown
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Daily Breakdown', 0, 1);

$dailyDataQuery = $db->query("
    SELECT DATE(created_at) as sale_date, 
           SUM(CASE WHEN source = 'cash' THEN amount ELSE 0 END) as cash_sales,
           SUM(CASE WHEN source = 'credit' THEN amount ELSE 0 END) as credit_sales,
           SUM(amount) as total_sales
    FROM (
        SELECT DATE(created_at) as created_at, total as amount, 'cash' as source FROM orders 
        UNION ALL
        SELECT DATE(created_at) as created_at, total_amount as amount, 'credit' as source FROM credit_sales
    )
    GROUP BY sale_date
    ORDER BY sale_date ASC
");
$dailyData = $dailyDataQuery->fetchAll(PDO::FETCH_ASSOC);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 10, 'Date', 1);
$pdf->Cell(45, 10, 'Cash Sales', 1);
$pdf->Cell(45, 10, 'Credit Sales', 1);
$pdf->Cell(45, 10, 'Total', 1);
$pdf->Ln();

$pdf->SetFont('Arial', '', 10);
foreach ($dailyData as $day) {
    $pdf->Cell(50, 10, date('Y-m-d (D)', strtotime($day['sale_date'])), 1);
    $pdf->Cell(45, 10, 'N$' . number_format($day['cash_sales'], 2), 1);
    $pdf->Cell(45, 10, 'N$' . number_format($day['credit_sales'], 2), 1);
    $pdf->Cell(45, 10, 'N$' . number_format($day['total_sales'], 2), 1);
    $pdf->Ln();
}

// Top selling products
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Top Selling Products', 0, 1);

$topProductsQuery = $db->query("
    SELECT 
        t.product_name, 
        SUM(t.quantity) as total_qty, 
        SUM(t.price * t.quantity) as historical_value,
        p.price as current_price
    FROM (
        SELECT product_name, quantity, price FROM order_items
        JOIN orders ON order_items.order_id = orders.id
        UNION ALL
        SELECT product_name, quantity, price FROM credit_sale_items
        JOIN credit_sales ON credit_sale_items.sale_id = credit_sales.id
    ) t
    LEFT JOIN products p ON t.product_name = p.name
    GROUP BY t.product_name
    ORDER BY total_qty DESC
");
$topProducts = $topProductsQuery->fetchAll(PDO::FETCH_ASSOC);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(70, 10, 'Product', 1);
$pdf->Cell(30, 10, 'Quantity', 1);
$pdf->Cell(45, 10, 'Current Price', 1);
$pdf->Cell(45, 10, 'Total Value', 1);
$pdf->Ln();

$pdf->SetFont('Arial', '', 10);
foreach ($topProducts as $product) {
    $totalValue = $product['current_price'] * $product['total_qty'];
    
    $pdf->Cell(70, 10, $product['product_name'], 1);
    $pdf->Cell(30, 10, $product['total_qty'], 1);
    $pdf->Cell(45, 10, 'N$' . number_format($product['current_price'], 2), 1);
    $pdf->Cell(45, 10, 'N$' . number_format($totalValue, 2), 1);
    $pdf->Ln();
}

// Output the PDF
$fileName = 'Comprehensive_Report_' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $fileName);

// If reset parameter is set, reset the system
if (isset($_GET['reset']) && $_GET['reset'] == 'true') {
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Delete all records from orders and order_items tables
        $db->exec("DELETE FROM orders");
        $db->exec("DELETE FROM order_items");
        
        // Delete all records from credit_sales and credit_sale_items tables
        $db->exec("DELETE FROM credit_sales");
        $db->exec("DELETE FROM credit_sale_items");
        
        // Delete all records from payments table
        $db->exec("DELETE FROM payments");
        
        // Delete all records from cash_transactions table
        $db->exec("DELETE FROM cash_transactions");
        
        // Delete all records from eft_payments table
        $db->exec("DELETE FROM eft_payments");
        
        // Delete all records from credit_book table
        $db->exec("DELETE FROM credit_book");
        
        // Delete all records from stock_changes table
        $db->exec("DELETE FROM stock_changes");
        
        // Delete all records from damaged_goods table
        $db->exec("DELETE FROM damaged_goods");
        
        // Delete all records from creditors table
        $db->exec("DELETE FROM creditors");
        
        // Delete all records from user_log table
        $db->exec("DELETE FROM user_log");
        
        // Commit transaction
        $db->commit();
        
        // Redirect back to reports page with success message
        header('Location: reports.php?reset=success');
        exit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        die("Error resetting system: " . $e->getMessage());
    }
}
?> 