<?php
// Check activation status
$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

require('../fpdf/fpdf.php');

// Get week from URL parameter
$selectedWeek = isset($_GET['week']) ? $_GET['week'] : date('Y-W');
$weekParts = explode('-', $selectedWeek);
$year = (int)$weekParts[0];
$week = (int)ltrim($weekParts[1], '0');
if ($week < 1) $week = 1;
if ($week > 53) $week = 53;

// Calculate week start (Monday) and end (Sunday)
$weekStart = new DateTime();
$weekStart->setISODate($year, $week, 1); // Monday
$weekEnd = clone $weekStart;
$weekEnd->modify('+6 days'); // Sunday

$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr = $weekEnd->format('Y-m-d');

// Get business info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $businessInfo = [];
}

// Database connection
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    die("Connection failed: " . $db->errorInfo()[2]);
}

// Prepare weekly data (same as in weekly_sales.php)
$closingTime = $businessInfo['closing_time'] ?? '00:00';
$closingHour = (int)substr($closingTime, 0, 2);
$isAfterMidnight = $closingHour < 12;
$weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weeklyData = [];
for ($i = 0; $i < 7; $i++) {
    $currentDate = clone $weekStart;
    $currentDate->modify("+$i days");
    $dateStr = $currentDate->format('Y-m-d');
    $nextDay = clone $currentDate;
    $nextDay->modify('+1 day');
    $nextDayStr = $nextDay->format('Y-m-d');

    // Cash sales for this day
    $cashSalesQuery = $db->prepare("
        SELECT SUM(total) FROM (
            SELECT o.total, 
            CASE 
                WHEN strftime('%H:%M', o.created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(o.created_at, '-1 day'))
                ELSE date(o.created_at)
            END AS business_date
            FROM orders o
            LEFT JOIN eft_payments e ON o.id = e.order_id
            WHERE e.order_id IS NULL
        ) 
        WHERE business_date = :date
    ");
    $cashSalesQuery->bindParam(':date', $dateStr);
    $cashSalesQuery->execute();
    $cashSales = $cashSalesQuery->fetchColumn() ?: 0;

    // EFT sales for this day
    $eftSalesQuery = $db->prepare("
        SELECT SUM(e.amount) 
        FROM eft_payments e 
        JOIN orders o ON e.order_id = o.id 
        WHERE (
            (DATE(o.created_at) = :date AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
            (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $eftSalesQuery->bindParam(':date', $dateStr);
    $eftSalesQuery->bindParam(':nextDay', $nextDayStr);
    $eftSalesQuery->execute();
    $eftSales = $eftSalesQuery->fetchColumn() ?: 0;

    // Credit sales issued for this day
    $creditIssuedQuery = $db->prepare("
        SELECT SUM(total_amount) 
        FROM credit_sales 
        WHERE (
            (DATE(created_at) = :date AND strftime('%H:%M', created_at) >= '$closingTime') OR
            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        ) AND payment_status IN ('unpaid', 'partial')
    ");
    $creditIssuedQuery->bindParam(':date', $dateStr);
    $creditIssuedQuery->bindParam(':nextDay', $nextDayStr);
    $creditIssuedQuery->execute();
    $creditIssued = $creditIssuedQuery->fetchColumn() ?: 0;

    // Credit payments received for this day
    $creditPaymentsQuery = $db->prepare("
        SELECT SUM(p.amount) 
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE (
            (DATE(p.payment_date) = :date AND strftime('%H:%M', p.payment_date) >= '$closingTime') OR
            (DATE(p.payment_date) = :nextDay AND strftime('%H:%M', p.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $creditPaymentsQuery->bindParam(':date', $dateStr);
    $creditPaymentsQuery->bindParam(':nextDay', $nextDayStr);
    $creditPaymentsQuery->execute();
    $creditPayments = $creditPaymentsQuery->fetchColumn() ?: 0;

    // EFT credit payments for this day
    $eftCreditPaymentsQuery = $db->prepare("
        SELECT SUM(p.amount) 
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.payment_status = 'eft' AND (
            (DATE(p.payment_date) = :date AND strftime('%H:%M', p.payment_date) >= '$closingTime') OR
            (DATE(p.payment_date) = :nextDay AND strftime('%H:%M', p.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $eftCreditPaymentsQuery->bindParam(':date', $dateStr);
    $eftCreditPaymentsQuery->bindParam(':nextDay', $nextDayStr);
    $eftCreditPaymentsQuery->execute();
    $eftCreditPayments = $eftCreditPaymentsQuery->fetchColumn() ?: 0;

    // Cash out for this day
    $cashOutQuery = $db->prepare("
        SELECT SUM(amount) FROM (
            SELECT amount,
            CASE 
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date
            FROM cash_transactions
            WHERE type = 'cash-out'
        )
        WHERE business_date = :date
    ");
    $cashOutQuery->bindParam(':date', $dateStr);
    $cashOutQuery->execute();
    $cashOut = $cashOutQuery->fetchColumn() ?: 0;

    // Calculate totals
    $totalIncome = $cashSales + $eftSales + $creditPayments + $eftCreditPayments;
    $totalExpenses = $cashOut;
    $netAmount = $totalIncome - $totalExpenses;

    $weeklyData[] = [
        'day' => $weekDays[$i],
        'date' => $dateStr,
        'cash_sales' => $cashSales,
        'eft_sales' => $eftSales,
        'credit_issued' => $creditIssued,
        'credit_payments' => $creditPayments,
        'eft_credit_payments' => $eftCreditPayments,
        'cash_out' => $cashOut,
        'total_income' => $totalIncome,
        'total_expenses' => $totalExpenses,
        'net_amount' => $netAmount
    ];
}

// Calculate weekly totals
$weeklyTotals = [
    'cash_sales' => array_sum(array_column($weeklyData, 'cash_sales')),
    'eft_sales' => array_sum(array_column($weeklyData, 'eft_sales')),
    'credit_issued' => array_sum(array_column($weeklyData, 'credit_issued')),
    'credit_payments' => array_sum(array_column($weeklyData, 'credit_payments')),
    'eft_credit_payments' => array_sum(array_column($weeklyData, 'eft_credit_payments')),
    'cash_out' => array_sum(array_column($weeklyData, 'cash_out')),
    'total_income' => array_sum(array_column($weeklyData, 'total_income')),
    'total_expenses' => array_sum(array_column($weeklyData, 'total_expenses')),
    'net_amount' => array_sum(array_column($weeklyData, 'net_amount'))
];

// Calculate running balance (ledger style)
$runningBalance = 0;
foreach ($weeklyData as &$day) {
    $runningBalance += $day['net_amount'];
    $day['running_balance'] = $runningBalance;
}
unset($day);

// PDF generation
class WeeklyReportPDF extends FPDF {
    function Header() {
        global $weekStart, $weekEnd, $businessInfo;
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 10, $businessInfo['name'] ?? 'Business', 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 10, 'Weekly Sales Report', 0, 1, 'C');
        $this->Cell(0, 10, 'Week of ' . $weekStart->format('M j, Y') . ' - ' . $weekEnd->format('M j, Y'), 0, 1, 'C');
        $this->Ln(5);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

$pdf = new WeeklyReportPDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Summary section
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(0, 10, 'Summary', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(60, 8, 'Cash Sales:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['cash_sales'], 2), 0, 1);
$pdf->Cell(60, 8, 'EFT Sales:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['eft_sales'], 2), 0, 1);
$pdf->Cell(60, 8, 'Credit Issued:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['credit_issued'], 2), 0, 1);
$pdf->Cell(60, 8, 'Credit Payments:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['credit_payments'], 2), 0, 1);
$pdf->Cell(60, 8, 'EFT Credit Payments:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['eft_credit_payments'], 2), 0, 1);
$pdf->Cell(60, 8, 'Cash Out:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['cash_out'], 2), 0, 1);
$pdf->Cell(60, 8, 'Total Income:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['total_income'], 2), 0, 1);
$pdf->Cell(60, 8, 'Total Expenses:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['total_expenses'], 2), 0, 1);
$pdf->Cell(60, 8, 'Net Amount:', 0);
$pdf->Cell(0, 8, 'N$' . number_format($weeklyTotals['net_amount'], 2), 0, 1);
$pdf->Ln(8);

// Daily breakdown table
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Daily Breakdown', 0, 1);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 8, 'Metric', 1);
foreach ($weeklyData as $day) {
    $pdf->Cell(25, 8, $day['day'] . "\n" . date('M j', strtotime($day['date'])), 1);
}
$pdf->Ln();

$metrics = [
    'cash_sales' => 'Cash Sales',
    'eft_sales' => 'EFT Sales',
    'credit_issued' => 'Credit Issued',
    'credit_payments' => 'Credit Payments',
    'eft_credit_payments' => 'EFT Credit',
    'cash_out' => 'Cash Out',
    'net_amount' => 'Net Amount',
    'running_balance' => 'Running Balance',
];
foreach ($metrics as $key => $label) {
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(30, 8, $label, 1);
    foreach ($weeklyData as $day) {
        $val = 'N$' . number_format($day[$key], 2);
        $pdf->Cell(25, 8, $val, 1);
    }
    $pdf->Ln();
}

// Output the PDF
$fileName = 'Weekly_Report_' . $weekStart->format('Y_m_d') . '_to_' . $weekEnd->format('Y_m_d') . '.pdf';
$pdf->Output('D', $fileName); 