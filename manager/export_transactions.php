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

// Include the PhpSpreadsheet library
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Create a new Spreadsheet object
$spreadsheet = new Spreadsheet();

try {
    // Connect to the database
    $pdo = new PDO('sqlite:../pos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the date range if provided
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // Default to first day of current month
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Default to today

    // Add date range to the end of the file name
    $startDateFormatted = date('Y-m-d', strtotime($startDate));
    $endDateFormatted = date('Y-m-d', strtotime($endDate));
    $fileName = "Transactions_{$startDateFormatted}_to_{$endDateFormatted}.xlsx";

    // Create Cash Transactions sheet
    $cashSheet = $spreadsheet->getActiveSheet();
    $cashSheet->setTitle('Cash Transactions');

    // Set headers for Cash Transactions
    $cashSheet->setCellValue('A1', 'ID');
    $cashSheet->setCellValue('B1', 'Type');
    $cashSheet->setCellValue('C1', 'Amount');
    $cashSheet->setCellValue('D1', 'Description');
    $cashSheet->setCellValue('E1', 'Date');
    $cashSheet->setCellValue('F1', 'Cashier');

    // Format header row
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '4472C4'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
            ],
        ],
    ];
    $cashSheet->getStyle('A1:F1')->applyFromArray($headerStyle);

    // Get cash transactions data
    $cashQuery = "SELECT id, type, amount, description, created_at, cashier_id FROM cash_transactions 
                  WHERE created_at BETWEEN :start_date AND :end_date 
                  ORDER BY created_at DESC";
    $cashStmt = $pdo->prepare($cashQuery);
    $cashStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $cashTransactions = $cashStmt->fetchAll(PDO::FETCH_ASSOC);

    // Populate cash transactions data
    $row = 2;
    foreach ($cashTransactions as $transaction) {
        $cashSheet->setCellValue('A' . $row, $transaction['id']);
        $cashSheet->setCellValue('B' . $row, $transaction['type']);
        $cashSheet->setCellValue('C' . $row, $transaction['amount']);
        $cashSheet->setCellValue('D' . $row, $transaction['description']);
        $cashSheet->setCellValue('E' . $row, $transaction['created_at']);
        $cashSheet->setCellValue('F' . $row, $transaction['cashier_id']);
        $row++;
    }

    // Auto-size columns
    foreach (range('A', 'F') as $col) {
        $cashSheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Create Credit Sales sheet
    $creditSheet = $spreadsheet->createSheet();
    $creditSheet->setTitle('Credit Sales');

    // Set headers for Credit Sales
    $creditSheet->setCellValue('A1', 'ID');
    $creditSheet->setCellValue('B1', 'Creditor');
    $creditSheet->setCellValue('C1', 'Total Amount');
    $creditSheet->setCellValue('D1', 'Paid Amount');
    $creditSheet->setCellValue('E1', 'Payment Status');
    $creditSheet->setCellValue('F1', 'Date');
    $creditSheet->setCellValue('G1', 'Cashier');

    // Apply header style
    $creditSheet->getStyle('A1:G1')->applyFromArray($headerStyle);

    // Get credit sales data
    $creditQuery = "SELECT cs.id, c.name as creditor_name, cs.total_amount, cs.paid_amount, cs.payment_status, cs.created_at, cs.cashier_id 
                    FROM credit_sales cs
                    LEFT JOIN creditors c ON cs.creditor_id = c.id
                    WHERE cs.created_at BETWEEN :start_date AND :end_date
                    ORDER BY cs.created_at DESC";
    $creditStmt = $pdo->prepare($creditQuery);
    $creditStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $creditSales = $creditStmt->fetchAll(PDO::FETCH_ASSOC);

    // Populate credit sales data
    $row = 2;
    foreach ($creditSales as $sale) {
        $creditSheet->setCellValue('A' . $row, $sale['id']);
        $creditSheet->setCellValue('B' . $row, $sale['creditor_name']);
        $creditSheet->setCellValue('C' . $row, $sale['total_amount']);
        $creditSheet->setCellValue('D' . $row, $sale['paid_amount']);
        $creditSheet->setCellValue('E' . $row, $sale['payment_status']);
        $creditSheet->setCellValue('F' . $row, $sale['created_at']);
        $creditSheet->setCellValue('G' . $row, $sale['cashier_id']);
        $row++;
    }

    // Auto-size columns
    foreach (range('A', 'G') as $col) {
        $creditSheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Create EFT Payments sheet
    $eftSheet = $spreadsheet->createSheet();
    $eftSheet->setTitle('EFT Payments');

    // Set headers for EFT Payments
    $eftSheet->setCellValue('A1', 'ID');
    $eftSheet->setCellValue('B1', 'Order ID');
    $eftSheet->setCellValue('C1', 'Transaction Reference');
    $eftSheet->setCellValue('D1', 'Wallet Provider');
    $eftSheet->setCellValue('E1', 'Amount');
    $eftSheet->setCellValue('F1', 'Date');
    $eftSheet->setCellValue('G1', 'Status');
    $eftSheet->setCellValue('H1', 'Cashier');

    // Apply header style
    $eftSheet->getStyle('A1:H1')->applyFromArray($headerStyle);

    // Get EFT payments data
    $eftQuery = "SELECT id, order_id, transaction_ref, wallet_provider, amount, payment_date, status, cashier_id 
                 FROM eft_payments
                 WHERE payment_date BETWEEN :start_date AND :end_date
                 ORDER BY payment_date DESC";
    $eftStmt = $pdo->prepare($eftQuery);
    $eftStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $eftPayments = $eftStmt->fetchAll(PDO::FETCH_ASSOC);

    // Populate EFT payments data
    $row = 2;
    foreach ($eftPayments as $payment) {
        $eftSheet->setCellValue('A' . $row, $payment['id']);
        $eftSheet->setCellValue('B' . $row, $payment['order_id']);
        $eftSheet->setCellValue('C' . $row, $payment['transaction_ref']);
        $eftSheet->setCellValue('D' . $row, $payment['wallet_provider']);
        $eftSheet->setCellValue('E' . $row, $payment['amount']);
        $eftSheet->setCellValue('F' . $row, $payment['payment_date']);
        $eftSheet->setCellValue('G' . $row, $payment['status']);
        $eftSheet->setCellValue('H' . $row, $payment['cashier_id']);
        $row++;
    }

    // Auto-size columns
    foreach (range('A', 'H') as $col) {
        $eftSheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Create Orders sheet
    $ordersSheet = $spreadsheet->createSheet();
    $ordersSheet->setTitle('Orders');

    // Set headers for Orders
    $ordersSheet->setCellValue('A1', 'ID');
    $ordersSheet->setCellValue('B1', 'Total');
    $ordersSheet->setCellValue('C1', 'Cash Received');
    $ordersSheet->setCellValue('D1', 'Date');
    $ordersSheet->setCellValue('E1', 'Cashier');

    // Apply header style
    $ordersSheet->getStyle('A1:E1')->applyFromArray($headerStyle);

    // Get orders data
    $ordersQuery = "SELECT id, total, cash_received, created_at, cashier_id 
                   FROM orders
                   WHERE created_at BETWEEN :start_date AND :end_date
                   ORDER BY created_at DESC";
    $ordersStmt = $pdo->prepare($ordersQuery);
    $ordersStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Populate orders data
    $row = 2;
    foreach ($orders as $order) {
        $ordersSheet->setCellValue('A' . $row, $order['id']);
        $ordersSheet->setCellValue('B' . $row, $order['total']);
        $ordersSheet->setCellValue('C' . $row, $order['cash_received']);
        $ordersSheet->setCellValue('D' . $row, $order['created_at']);
        $ordersSheet->setCellValue('E' . $row, $order['cashier_id']);
        $row++;
    }

    // Auto-size columns
    foreach (range('A', 'E') as $col) {
        $ordersSheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Create Summary sheet
    $summarySheet = $spreadsheet->createSheet();
    $summarySheet->setTitle('Summary');

    // Set headers for Summary
    $summarySheet->setCellValue('A1', 'Transaction Type');
    $summarySheet->setCellValue('B1', 'Count');
    $summarySheet->setCellValue('C1', 'Total Amount');

    // Apply header style
    $summarySheet->getStyle('A1:C1')->applyFromArray($headerStyle);

    // Calculate summary data
    // Cash in transactions
    $cashInQuery = "SELECT COUNT(*) as count, SUM(amount) as total FROM cash_transactions 
                   WHERE type = 'cash-in' AND created_at BETWEEN :start_date AND :end_date";
    $cashInStmt = $pdo->prepare($cashInQuery);
    $cashInStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $cashIn = $cashInStmt->fetch(PDO::FETCH_ASSOC);

    // Cash out transactions
    $cashOutQuery = "SELECT COUNT(*) as count, SUM(amount) as total FROM cash_transactions 
                    WHERE type = 'cash-out' AND created_at BETWEEN :start_date AND :end_date";
    $cashOutStmt = $pdo->prepare($cashOutQuery);
    $cashOutStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $cashOut = $cashOutStmt->fetch(PDO::FETCH_ASSOC);

    // Credit sales
    $creditSalesQuery = "SELECT COUNT(*) as count, SUM(total_amount) as total FROM credit_sales 
                        WHERE created_at BETWEEN :start_date AND :end_date";
    $creditSalesStmt = $pdo->prepare($creditSalesQuery);
    $creditSalesStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $creditSalesTotal = $creditSalesStmt->fetch(PDO::FETCH_ASSOC);

    // EFT payments
    $eftQuery = "SELECT COUNT(*) as count, SUM(amount) as total FROM eft_payments 
                WHERE payment_date BETWEEN :start_date AND :end_date";
    $eftStmt = $pdo->prepare($eftQuery);
    $eftStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $eftTotal = $eftStmt->fetch(PDO::FETCH_ASSOC);

    // Orders
    $ordersQuery = "SELECT COUNT(*) as count, SUM(total) as total FROM orders 
                   WHERE created_at BETWEEN :start_date AND :end_date";
    $ordersStmt = $pdo->prepare($ordersQuery);
    $ordersStmt->execute([
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $ordersTotal = $ordersStmt->fetch(PDO::FETCH_ASSOC);

    // Populate summary data
    $summarySheet->setCellValue('A2', 'Cash In');
    $summarySheet->setCellValue('B2', $cashIn['count']);
    $summarySheet->setCellValue('C2', $cashIn['total'] ?: 0);

    $summarySheet->setCellValue('A3', 'Cash Out');
    $summarySheet->setCellValue('B3', $cashOut['count']);
    $summarySheet->setCellValue('C3', $cashOut['total'] ?: 0);

    $summarySheet->setCellValue('A4', 'Credit Sales');
    $summarySheet->setCellValue('B4', $creditSalesTotal['count']);
    $summarySheet->setCellValue('C4', $creditSalesTotal['total'] ?: 0);

    $summarySheet->setCellValue('A5', 'EFT Payments');
    $summarySheet->setCellValue('B5', $eftTotal['count']);
    $summarySheet->setCellValue('C5', $eftTotal['total'] ?: 0);

    $summarySheet->setCellValue('A6', 'Orders');
    $summarySheet->setCellValue('B6', $ordersTotal['count']);
    $summarySheet->setCellValue('C6', $ordersTotal['total'] ?: 0);

    // Calculate net cash flow
    $netCashFlow = ($cashIn['total'] ?: 0) - ($cashOut['total'] ?: 0);
    $summarySheet->setCellValue('A8', 'Net Cash Flow');
    $summarySheet->setCellValue('C8', $netCashFlow);

    // Format the summary sheet
    $summarySheet->getStyle('A8:C8')->applyFromArray([
        'font' => [
            'bold' => true,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E2EFDA'],
        ],
    ]);

    // Auto-size columns
    foreach (range('A', 'C') as $col) {
        $summarySheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Set the first sheet as active
    $spreadsheet->setActiveSheetIndex(0);

    // Create Excel file
    $writer = new Xlsx($spreadsheet);
    
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    
    // Save to PHP output stream
    $writer->save('php://output');
    exit;
    
} catch (PDOException $e) {
    // Handle database errors
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    // Handle other errors
    die("Error: " . $e->getMessage());
} 