<?php
// cash_pdf.php - Generate PDF cash-up report using FPDF

require('fpdf/fpdf.php'); // Make sure FPDF is installed
require __DIR__ . '/vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

class CashupPDF extends FPDF {
    // Page header
    function Header() {
        // Logo (if you have one)
        // $this->Image('logo.png', 10, 6, 30);
        
        // Title
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'Daily Cash-up Report', 0, 1, 'C');
        
        // Date
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 10, 'Date: ' . $_POST['date'], 0, 1, 'C');
        $this->Ln(5);
    }

    // Page footer
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// Get data from POST request
if (isset($_POST['is_cashup']) && $_POST['is_cashup'] === 'true') {
    // Debug logging
    error_log("POST data received: " . print_r($_POST, true));
    
    // Create new PDF instance
    $pdf = new CashupPDF();
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('Arial', '', 12);
    
    // Add cashier information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Cashier: ' . $_POST['cashier_username'], 0, 1);
    $pdf->Ln(10);
    
    // =====================
    // Simplified Income and Expenses Section
    // =====================
    try {
        $db = new PDO('sqlite:pos.db');
        $infoDb = new PDO('sqlite:info.db');
        $businessInfo = $infoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $closingTime = $businessInfo && !empty($businessInfo['closing_time']) ? $businessInfo['closing_time'] : '00:00';
        $closingHour = (int)substr($closingTime, 0, 2);
        $isAfterMidnight = $closingHour < 12;
        $selectedDate = $_POST['date'];
        
        // Query for simplified breakdown
        $sql = "
            SELECT 
                t.business_date as sale_date,
                SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'cash' THEN t.amount ELSE 0 END) as cash_sales,
                SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_unpaid' THEN t.amount ELSE 0 END) as credit_unpaid,
                SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_payment' THEN t.amount ELSE 0 END) as credit_cash,
                SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_eft' THEN t.amount ELSE 0 END) as credit_eft,
                SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'eft' THEN t.amount ELSE 0 END) as eft_sales,
                SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE 0 END) as total_income,
                SUM(CASE WHEN t.transaction_type = 'expense' THEN t.amount ELSE 0 END) as total_expense
            FROM (
                SELECT 
                    CASE 
                        WHEN strftime('%H:%M', o.created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                        THEN date(datetime(o.created_at, '-1 day'))
                        ELSE date(o.created_at)
                    END AS business_date, 
                    o.total as amount,
                    CASE 
                        WHEN e.order_id IS NOT NULL THEN 'eft'
                        ELSE 'cash'
                    END as source,
                    'income' as transaction_type
                FROM orders o
                LEFT JOIN eft_payments e ON o.id = e.order_id
                
                UNION ALL
                
                SELECT 
                    CASE 
                        WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                        THEN date(datetime(created_at, '-1 day'))
                        ELSE date(created_at)
                    END AS business_date, 
                    total_amount as amount, 
                    'credit_unpaid' as source,
                    'income' as transaction_type
                FROM credit_sales
                WHERE payment_status IN ('unpaid', 'partial')
                
                UNION ALL
                
                SELECT 
                    CASE 
                        WHEN strftime('%H:%M', p.payment_date) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                        THEN date(datetime(p.payment_date, '-1 day'))
                        ELSE date(p.payment_date)
                    END AS business_date,
                    p.amount as amount,
                    CASE
                        WHEN cs.payment_status = 'eft' THEN 'credit_eft'
                        ELSE 'credit_payment'
                    END as source,
                    'income' as transaction_type
                FROM payments p
                JOIN credit_sales cs ON p.sale_id = cs.id
                
                UNION ALL
                
                SELECT 
                    CASE 
                        WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                        THEN date(datetime(created_at, '-1 day'))
                        ELSE date(created_at)
                    END AS business_date,
                    amount,
                    'cash-out' as source,
                    'expense' as transaction_type
                FROM cash_transactions
                WHERE type = 'cash-out'
            ) t
            WHERE business_date = :selectedDate
            GROUP BY sale_date
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':selectedDate', $selectedDate);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Extract data
        $cashSales = $row['cash_sales'] ?? 0;
        $creditUnpaid = $row['credit_unpaid'] ?? 0;
        $creditCash = $row['credit_cash'] ?? 0;
        $creditEft = $row['credit_eft'] ?? 0;
        $eftSales = $row['eft_sales'] ?? 0;
        $totalIncome = $row['total_income'] ?? 0;
        $totalExpense = $row['total_expense'] ?? 0;
        
        // Simplified display
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, 'Summary', 0, 1, 'C');
        $pdf->Ln(5);
        $pdf->SetFont('Arial', '', 12);
        
        // Cash
        $pdf->Cell(80, 10, 'Cash:', 0, 0, 'L');
        $pdf->Cell(50, 10, 'N$' . number_format($cashSales + $creditCash, 2), 0, 1, 'R');
        
        // EFT
        $pdf->Cell(80, 10, 'EFT:', 0, 0, 'L');
        $pdf->Cell(50, 10, 'N$' . number_format($creditEft + $eftSales, 2), 0, 1, 'R');
        
        // Credit
        $pdf->Cell(80, 10, 'Credit:', 0, 0, 'L');
        $pdf->Cell(50, 10, 'N$' . number_format($creditUnpaid, 2), 0, 1, 'R');
        
        $pdf->Ln(3);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Line($pdf->GetX() + 10, $pdf->GetY(), $pdf->GetX() + 130, $pdf->GetY());
        $pdf->Ln(5);
        
        // Income
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(80, 10, 'Total Income:', 0, 0, 'L');
        $pdf->Cell(50, 10, 'N$' . number_format($totalIncome, 2), 0, 1, 'R');
        
        // Expenses
        $pdf->Cell(80, 10, 'Total Expenses:', 0, 0, 'L');
        $pdf->Cell(50, 10, 'N$' . number_format($totalExpense, 2), 0, 1, 'R');
        
        $pdf->Ln(3);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Line($pdf->GetX() + 10, $pdf->GetY(), $pdf->GetX() + 130, $pdf->GetY());
        $pdf->Ln(5);
        
        $pdf->Ln(3);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Line($pdf->GetX() + 10, $pdf->GetY(), $pdf->GetX() + 130, $pdf->GetY());
        $pdf->Ln(5);
        
        // Expected Cash in Till - use expected_cash if available, otherwise cash_available_in_till
        $expectedCash = floatval($_POST['expected_cash'] ?? $_POST['cash_available_in_till'] ?? 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(80, 10, 'Expected Cash in Till:', 0, 0, 'L');
        $pdf->Cell(50, 10, 'N$' . number_format($expectedCash, 2), 0, 1, 'R');
        
        // Cash on Hand
        $cashOnHand = floatval($_POST['cash_on_hand'] ?? 0);
        $pdf->Cell(80, 10, 'Cash on Hand:', 0, 0, 'L');
        $pdf->Cell(50, 10, 'N$' . number_format($cashOnHand, 2), 0, 1, 'R');
        
        $pdf->Ln(3);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Line($pdf->GetX() + 10, $pdf->GetY(), $pdf->GetX() + 130, $pdf->GetY());
        $pdf->Ln(5);
        
        // Actual Cash in Till (if provided)
        if (isset($_POST['actual_cash_in_till'])) {
            $actualCash = floatval($_POST['actual_cash_in_till']);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(80, 10, 'Actual Cash in Till:', 0, 0, 'L');
            $pdf->Cell(50, 10, 'N$' . number_format($actualCash, 2), 0, 1, 'R');
        }
        
        // Shortage/Surplus
        $difference = floatval($_POST['cash_difference'] ?? 0);
        if ($difference != 0) {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(80, 10, ($difference > 0 ? 'Surplus:' : 'Shortage:'), 0, 0, 'L');
            $pdf->SetTextColor($difference > 0 ? 0 : 255, 0, 0);
            $pdf->Cell(50, 10, 'N$' . number_format(abs($difference), 2), 0, 1, 'R');
            $pdf->SetTextColor(0, 0, 0);
        } else {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(80, 10, 'Shortage/Surplus:', 0, 0, 'L');
            $pdf->Cell(50, 10, 'N$0.00', 0, 1, 'R');
        }
        
        $pdf->Ln(10);
    } catch (Exception $e) {
        $pdf->Ln(10);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, 'Error loading data', 0, 1, 'C');
    }
    
    // Output PDF to browser for download
    $filename = 'Cashup_Report_' . $_POST['date'] . '.pdf';
    $pdf->Output('D', $filename);

    // Send cash-up data to receipt.php for printing
    $cashupData = [
        'is_cashup_report' => true,
        'date' => $_POST['date'],
        'cashier_username' => $_POST['cashier_username'],
        'total_cash_sales' => $_POST['total_cash_sales'],
        'eft_sales_total' => $_POST['eft_sales_total'],
        'unpaid_credit' => $_POST['unpaid_credit'],
        'cash_on_hand' => $_POST['cash_on_hand'],
        'cash_available_in_till' => $_POST['cash_available_in_till'],
        'expected_cash' => $_POST['expected_cash'] ?? $_POST['cash_available_in_till'], // Add expected cash
        'actual_cash_in_till' => $_POST['actual_cash_in_till'],
        'cash_difference' => $_POST['cash_difference'],
        'total_cash_in' => $_POST['total_cash_in'],
        'total_cash_out' => $_POST['total_cash_out'],
        'cumulative_cash_sales' => $_POST['cumulative_cash_sales'],
        'cumulative_paid_credit' => $_POST['cumulative_paid_credit'],
        // Add income/expense breakdown if available
        'cash_sales' => $cashSales ?? 0,
        'credit_cash' => $creditCash ?? 0,
        'credit_eft' => $creditEft ?? 0,
        'eft_sales' => $eftSales ?? 0,
        'credit_unpaid' => $creditUnpaid ?? 0,
        'total_income' => $totalIncome ?? 0,
        'total_expense' => $totalExpense ?? 0,
        'net_amount' => $netAmount ?? 0
    ];
    // Use cURL to POST to receipt.php
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/receipt.php');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cashupData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    // End script
    exit;
} else {
    // Return error if not a cashup request
    echo json_encode(['error' => 'Invalid request']);
}
?>