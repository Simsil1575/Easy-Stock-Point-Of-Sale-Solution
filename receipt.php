<?php
// Suppress warnings and notices, and clear output buffer
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
if (ob_get_level()) ob_clean();
/* Call this file 'hello-world.php' */
require __DIR__ . '/vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

// Get the order data from the POST request
$orderData = json_decode(file_get_contents('php://input'), true);

if (!$orderData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No order data received']);
    exit;
}

// Open cash drawer without printing when requested
if (isset($orderData['open_drawer_only']) && $orderData['open_drawer_only']) {
    try {
        // Determine printer to use based on client IP (same logic as below)
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
        $printerName = '';
        $isNetworkPrinter = false;
        if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        } else if ($clientIP === '192.168.178.87') {
            $printerName = "POSPrinter POS-80C";
            $isNetworkPrinter = true;
        } else {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        }

        error_log("Opening cash drawer - Client IP: $clientIP, Printer: $printerName, Network: " . ($isNetworkPrinter ? 'Yes' : 'No'));

        if ($isNetworkPrinter) {
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            $connector = new WindowsPrintConnector($printerName);
        }

        $printer = new Printer($connector);
        $printer->pulse();
        $printer->close();

        error_log("Cash drawer opened successfully");

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Drawer opened',
            'printer_used' => $printerName,
            'client_ip' => $clientIP,
            'connection_type' => $isNetworkPrinter ? 'network' : 'local'
        ]);
        exit;
    } catch (Exception $e) {
        error_log("Cash drawer opening failed: " . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to open drawer: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle cash-up receipt printing
if (isset($orderData['is_cashup_report']) && $orderData['is_cashup_report']) {
    try {
        // Set timezone to Namibia
        date_default_timezone_set('Africa/Harare');

        // Connect to database and get business info
        $dbInfo = new PDO('sqlite:info.db');
        $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$businessInfo) {
            $businessInfo = [
                'name' => 'POS SOLUTION',
                'location' => 'Your Business Address',
                'phone' => 'Your Phone Number',
                'footer_text' => 'Thank you for your purchase!',
                'printer_port' => 'COM4',
                'vat_inclusive' => 'exclusive',
                'vat_rate' => 15.0
            ];
        }

        // Connect to POS database and calculate expected cash using same logic as cash.php
        $dbPos = new PDO('sqlite:pos.db');
        
        // Get business closing time from business_info
        $closingTime = $businessInfo['closing_time'] ?? '22:00'; // Default to 10:00 PM if not set
        
        // Calculate business day boundaries based on closing time
        $closingHour = (int)substr($closingTime, 0, 2);
        $closingMinute = (int)substr($closingTime, 3, 2);
        
        // If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
        // that happened after midnight but before closing time as part of the previous day
        $isAfterMidnight = $closingHour < 12;
        
        // Get the selected date from order data
        $selectedDate = $orderData['date'];
        $nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
        
        // 1. Calculate cash in transactions for the selected date
        $cashInQuery = $dbPos->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type='cash-in' AND (
                (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $cashInQuery->bindParam(':selectedDate', $selectedDate);
        $cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashInQuery->bindParam(':closingTime', $closingTime);
        $cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $cashInQuery->execute();
        $totalCashIn = $cashInQuery->fetchColumn();
        
        // 2. Calculate cash sales (excluding EFT payments)
        $eftTableExists = false;
        try {
            $checkEftTable = $dbPos->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
        }
        
        if ($eftTableExists) {
            $cashSalesQuery = $dbPos->prepare("
                SELECT COALESCE(SUM(o.total), 0)
                FROM orders o
                LEFT JOIN eft_payments e ON o.id = e.order_id
                WHERE e.order_id IS NULL AND (
                    (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
                    (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
                )
            ");
            $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
            $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $cashSalesQuery->bindParam(':closingTime', $closingTime);
            $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $cashSalesQuery->execute();
        } else {
            $cashSalesQuery = $dbPos->prepare("
                SELECT COALESCE(SUM(total), 0) 
                FROM orders 
                WHERE (
                    (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                    (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                )
            ");
            $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
            $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $cashSalesQuery->bindParam(':closingTime', $closingTime);
            $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $cashSalesQuery->execute();
        }
        $totalCashSales = $cashSalesQuery->fetchColumn();
        
        // 3. Calculate credit payments received in cash
        $creditPaymentsQuery = $dbPos->prepare("
            SELECT COALESCE(SUM(p.amount), 0) 
            FROM payments p
            JOIN credit_sales cs ON p.sale_id = cs.id
            WHERE cs.payment_status = 'paid' AND (
                (DATE(p.payment_date) = :selectedDate AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
                (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
        $creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $creditPaymentsQuery->bindParam(':closingTime', $closingTime);
        $creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $creditPaymentsQuery->execute();
        $totalCreditPayments = $creditPaymentsQuery->fetchColumn();
        
        // 4. Calculate cash out (withdrawals)
        $cashOutQuery = $dbPos->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type='cash-out' AND (
                (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $cashOutQuery->bindParam(':selectedDate', $selectedDate);
        $cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $cashOutQuery->bindParam(':closingTime', $closingTime);
        $cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $cashOutQuery->execute();
        $totalCashOut = $cashOutQuery->fetchColumn();
        
        // Calculate the correct expected cash amount
        $calculatedExpectedCash = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
        // Printer selection logic (same as before)
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
        $printerName = '';
        $isNetworkPrinter = false;
        if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        } else if ($clientIP === '192.168.178.87') {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = true;
        } else {
            $printerName = "XP-58SERIES";
            $isNetworkPrinter = false;
        }
        // Create printer connection
        if ($isNetworkPrinter) {
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            $connector = new WindowsPrintConnector($printerName);
        }
        $printer = new Printer($connector);
        // Print simplified Z-report receipt (48-char format)
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        $printer->text("Z-REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Time: " . date('H:i') . "\n");
        $printer->text("Cashier: " . ($orderData['cashier_username'] ?? 'N/A') . "\n");
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->feed();
        
        // Essential totals only
        $cashTotal = ($orderData['cash_sales'] ?? $orderData['total_cash_sales'] ?? 0);
        $eftTotal = ($orderData['eft_sales'] ?? $orderData['total_eft_sales'] ?? 0);
        $grandTotal = ($orderData['grand_total'] ?? $orderData['total_income'] ?? 0);
        
        $printer->text(sprintf("%-20s N$%8.2f\n", "CASH SALES:", $cashTotal));
        $printer->text(sprintf("%-20s N$%8.2f\n", "EFT SALES:", $eftTotal));
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL SALES:", $grandTotal));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('=', 42) . "\n");
        
        // Shortage/Surplus if provided
        if (isset($orderData['cash_difference'])) {
            $difference = floatval($orderData['cash_difference']);
            if ($difference != 0) {
                $printer->feed();
                $printer->setEmphasis(true);
                $printer->text(sprintf("%-20s N$%8.2f\n", 
                    $difference > 0 ? "SURPLUS:" : "SHORTAGE:", 
                    abs($difference)));
                $printer->setEmphasis(false);
            }
        }
        
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("End of Report\n");
        $printer->feed(2);
        $printer->cut();
        $printer->pulse();
        $printer->close();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Cash-up receipt printed']);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

try {
    // Set timezone to Namibia
    date_default_timezone_set('Africa/Harare');

    // Validate input data
    if (!isset($orderData) || empty($orderData)) {
        throw new Exception('No order data received');
    }

    // Only print if explicitly requested (print_only flag) OR for special receipt types
    // Special receipt types that should always print:
    // - Cash-up reports (is_cashup_report)
    // - Balance receipts (is_balance_receipt, is_tab_balance_receipt)
    // - Payment receipts (is_payment_receipt)
    // - Drawer-only operations (open_drawer_only)
    $shouldPrint = isset($orderData['print_only']) || 
                   isset($orderData['is_cashup_report']) || 
                   isset($orderData['is_balance_receipt']) || 
                   isset($orderData['is_tab_balance_receipt']) || 
                   isset($orderData['is_payment_receipt']) ||
                   isset($orderData['open_drawer_only']);

    // If printing is not explicitly requested, return success without printing
    if (!$shouldPrint) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Print not requested - receipt skipped'
        ]);
        exit;
    }

    // Prevent printing regular receipts for tab sales - only print kitchen tickets
    // Exception: Allow tab balance receipts (is_tab_balance_receipt), payment receipts (is_payment_receipt), 
    // and kitchen tickets (print_only with tab_id/table_id) to be printed
    if ((isset($orderData['tab_id']) || isset($orderData['table_id'])) && 
        !isset($orderData['is_tab_balance_receipt']) && 
        !isset($orderData['is_balance_receipt']) &&
        !isset($orderData['is_payment_receipt']) &&
        !isset($orderData['print_only'])) {
        // This is a tab sale - should only print kitchen ticket, not receipt
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Tab sale - kitchen ticket only, no receipt printed'
        ]);
        exit;
    }

    // Connect to database and get business info
    $db = new PDO('sqlite:info.db');
    $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    // If no business info found, use defaults
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!',
            'printer_port' => 'COM4',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    
    // Get VAT settings
    $vatInclusive = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $vatRate = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
    
    // Detect client IP address to determine which printer to use
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
    
    // Determine printer connection based on client IP
    $printerName = '';
    $isNetworkPrinter = false;
    
    if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
        // Local PC - use XP-58SERIES printer
        $printerName = "XP-58SERIES";
        $isNetworkPrinter = false;
    } else if ($clientIP === '192.168.178.87') {
        // Network PC - use POS-80C printer over network
        $printerName = "POSPrinter POS-80C";
        $isNetworkPrinter = true;
    } else {
        // Default to local printer for any other IP
        $printerName = "XP-58SERIES";
        $isNetworkPrinter = false;
    }
    
    // Create printer connection based on type
    try {
        if ($isNetworkPrinter) {
            // Network printer connection
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            // Local Windows printer connection
            $connector = new WindowsPrintConnector($printerName);
        }
        $printer = new Printer($connector);
    } catch (Exception $e) {
        throw new Exception("Printer connection failed for $printerName: " . $e->getMessage());
    }

    // Open the cash drawer BEFORE printing for eligible payments
    try {
        if (!isset($orderData['print_only']) && !isset($orderData['creditor_id']) && !isset($orderData['is_cashup_report']) && !isset($orderData['is_balance_receipt']) && !isset($orderData['is_tab_balance_receipt'])) {
            if (!isset($orderData['payment_method']) || ($orderData['payment_method'] !== 'e-wallet' && $orderData['payment_method'] !== 'credit')) {
                $printer->pulse();
            }
        }
    } catch (Exception $e) {
        error_log("Initial drawer open failed: " . $e->getMessage());
    }

    // Store header with emphasis (skip for tab sales/order receipts, but show for payment receipts and kitchen tickets)
    $isTabSale = isset($orderData['table_id']) || isset($orderData['tab_id']);
    $isPaymentReceipt = isset($orderData['is_payment_receipt']) && $orderData['is_payment_receipt'];
    
    // Skip header for kitchen tickets (tab sales) - no header or footer
    if (!$isTabSale || $isPaymentReceipt) {
        try {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $printer->text($businessInfo['name'] . "\n");
            $printer->selectPrintMode(); // Reset print mode
            $printer->setEmphasis(true);
            $printer->text($businessInfo['location'] . "\n");
            $printer->setEmphasis(false);
            $printer->text("Tel: " . $businessInfo['phone'] . "\n");
            $printer->text("Cashier: " . $orderData['cashier_username'] . "\n");
            $printer->feed();
        } catch (Exception $e) {
            error_log("Error printing header: " . $e->getMessage());
            throw new Exception("Failed to print header: " . $e->getMessage());
        }
    }

    // Check if this is a cash-up report or regular receipt
    if (isset($orderData['is_cashup_report']) && $orderData['is_cashup_report']) {
        // CASH-UP REPORT PRINTING
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->setEmphasis(true);
        $printer->text("DAILY CASH-UP REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Printed: " . date('Y-m-d H:i') . "\n");
        $printer->text("Printed by: " . ($orderData['cashier_username'] ?? 'Admin') . "\n");
        $printer->feed();
        
        // Print employee breakdown if employees array is provided
        if (isset($orderData['employees']) && is_array($orderData['employees']) && !empty($orderData['employees'])) {
            $printer->text(str_repeat('=', 42) . "\n");
            $printer->setEmphasis(true);
            $printer->text("EMPLOYEE BREAKDOWN\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('=', 42) . "\n");
            
            foreach ($orderData['employees'] as $employee) {
                $empName = $employee['name'] ?? 'Unknown';
                if (strlen($empName) > 20) {
                    $empName = substr($empName, 0, 17) . '...';
                }
                $printer->text(str_repeat('-', 42) . "\n");
                $printer->setEmphasis(true);
                $printer->text($empName . "\n");
                $printer->setEmphasis(false);
                $printer->text(sprintf("  Orders: %d\n", $employee['total_orders'] ?? 0));
                $printer->text(sprintf("  Cash:   N$%8.2f\n", $employee['cash_sales'] ?? 0));
                $printer->text(sprintf("  EFT:    N$%8.2f\n", $employee['eft_sales'] ?? 0));
                $printer->text(sprintf("  Total:  N$%8.2f\n", $employee['total_sales'] ?? 0));
            }
            $printer->text(str_repeat('=', 42) . "\n");
            $printer->feed();
        }
        
        $printer->text(str_repeat('=', 42) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $cashSales = $orderData['total_cash_sales'] ?? 0;
        $eftSales = $orderData['total_eft_sales'] ?? 0;
        $creditSales = $orderData['credit_sales'] ?? $orderData['credit_sales_total'] ?? 0;
        $tabSales = $orderData['tab_sales'] ?? 0;
        $unpaidCredit = $orderData['credit_unpaid'] ?? $orderData['unpaid_credit'] ?? 0;
        $grandTotal = $orderData['grand_total'] ?? ($cashSales + $eftSales);
        
        $printer->setEmphasis(true);
        $printer->text("GRAND TOTALS\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL CASH:", $cashSales));
        $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL EFT:", $eftSales));
        if ($creditSales > 0) {
            $printer->text(sprintf("%-20s N$%8.2f\n", "CREDIT SALES:", $creditSales));
        }
        if ($tabSales > 0) {
            $printer->text(sprintf("%-20s N$%8.2f\n", "TAB SALES:", $tabSales));
        }
        if ($unpaidCredit > 0) {
            $printer->text(sprintf("%-20s N$%8.2f\n", "UNPAID CREDIT:", $unpaidCredit));
        }
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s N$%8.2f\n", "GRAND TOTAL:", $grandTotal));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('=', 42) . "\n");
        $printer->feed();
        
        // Use expected_cash if available, otherwise fall back to cash_available_in_till
        $expectedCash = $orderData['expected_cash'] ?? $orderData['cash_available_in_till'] ?? 0;
        $cashOnHand = $orderData['cash_on_hand'] ?? 0;
        
        if ($expectedCash > 0 || $cashOnHand > 0) {
            $printer->text(str_repeat('-', 42) . "\n");
            // Expected Cash in Till
            if ($expectedCash > 0) {
                $printer->setEmphasis(true);
                $printer->text(sprintf("%-20s N$%8.2f\n", "EXPECTED CASH:", $expectedCash));
                $printer->setEmphasis(false);
            }
            
            // Cash on Hand
            if ($cashOnHand > 0) {
                $printer->text(sprintf("%-20s N$%8.2f\n", "CASH ON HAND:", $cashOnHand));
            }
            $printer->text(str_repeat('-', 42) . "\n");
        }
        
        // Add actual cash and difference if provided
        if (isset($orderData['actual_cash_in_till'])) {
            $actualCash = floatval($orderData['actual_cash_in_till']);
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s N$%8.2f\n", "ACTUAL CASH:", $actualCash));
            $printer->setEmphasis(false);
            
            // Calculate difference using the passed expected cash amount
            $difference = $actualCash - $expectedCash;
            if ($difference != 0) {
                $printer->text(sprintf("%-20s N$%8.2f\n", 
                    $difference > 0 ? "SURPLUS:" : "SHORTAGE:", 
                    abs($difference)));
            }
        }
        $printer->text(str_repeat('=', 42) . "\n");
        $printer->feed();
        // Signature line
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->text("Signature: ________________\n");
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*** End of Cash-up Report ***\n");
        $printer->feed();
    } else if (isset($orderData['is_balance_receipt']) && $orderData['is_balance_receipt']) {
        // Check if this is a tab balance receipt or credit sale balance receipt
        if (isset($orderData['is_tab_balance_receipt']) && $orderData['is_tab_balance_receipt']) {
            // TAB BALANCE RECEIPT - 48 chars
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("TAB BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->text(sprintf("%-10s %s\n", "Tab:", $orderData['tab_name'] ?? 'N/A'));
            if (isset($orderData['creditor_name']) && $orderData['creditor_name'] !== 'N/A') {
                $printer->text(sprintf("%-10s %s\n", "Client:", $orderData['creditor_name']));
            }
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->feed();
            
            // Print outstanding items
            if (isset($orderData['items']) && !empty($orderData['items'])) {
                $printer->setEmphasis(true);
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', 42) . "\n");
                
                $itemsTotal = 0;
                foreach ($orderData['items'] as $item) {
                    $itemName = $item['name'];
                    if (strlen($itemName) > 28) {
                        $itemName = substr($itemName, 0, 25) . '...';
                    }
                    $qty = intval($item['quantity']);
                    $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : (floatval($item['price']) / $qty);
                    $itemTotal = floatval($item['price']);
                    $itemsTotal += $itemTotal;
                    
                    $printer->text($itemName . "\n");
                    $printer->text(sprintf("  %dx N$%.2f = N$%.2f\n", $qty, $unitPrice, $itemTotal));
                }
                $printer->text(str_repeat('-', 42) . "\n");
                $printer->setEmphasis(true);
                $printer->text(sprintf("%-20s N$%8.2f\n", "ITEMS TOTAL:", $itemsTotal));
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', 42) . "\n");
                $printer->feed();
            }
            
            // Print total balance
            $printer->setEmphasis(true);
            $printer->text(str_repeat('=', 42) . "\n");
            $printer->text(sprintf("%-20s N$%8.2f\n", "OUTSTANDING BALANCE:", $orderData['total_balance']));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('=', 42) . "\n");
            $printer->feed(2);
        } else {
            // CREDIT SALE BALANCE RECEIPT - 48 chars (original format)
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->text(sprintf("%-10s %s\n", "Client:", $orderData['creditor_name']));
            $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $orderData['total_balance']));
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->feed();
            
            // Print transaction details
            if (isset($orderData['transactions']) && !empty($orderData['transactions'])) {
                $printer->setEmphasis(true);
                $printer->text("OUTSTANDING ITEMS\n");
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', 42) . "\n");
                
                foreach ($orderData['transactions'] as $transaction) {
                    $printer->text("Date: " . date('Y-m-d', strtotime($transaction['date'])) . "\n");
                    $printer->text(str_repeat('-', 42) . "\n");
                    
                    // Split items string into individual items
                    $items = explode(', ', $transaction['items']);
                    foreach ($items as $item) {
                        // Truncate item name if too long
                        if (strlen($item) > 42) {
                            $item = substr($item, 0, 39) . '...';
                        }
                        $printer->text($item . "\n");
                    }
                    
                    $printer->text(sprintf("%-20s N$%8.2f\n", "Balance:", $transaction['balance']));
                    $printer->text(str_repeat('-', 42) . "\n");
                    $printer->feed();
                }
            }
            
            // Print total balance again
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s N$%8.2f\n", "TOTAL BALANCE:", $orderData['total_balance']));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->feed(2);
        }
        
        // Footer section
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(4);
        
        // Add a line of dashes before cutting
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->feed(2);
    } else {
        // REGULAR RECEIPT PRINTING - 48 chars
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->setEmphasis(true);
        // Determine receipt number - check for order_id, sale_id, tab_id, or table_id
        $receiptNumber = isset($orderData['order_id']) ? $orderData['order_id'] : 
                        (isset($orderData['sale_id']) ? $orderData['sale_id'] : 
                        (isset($orderData['tab_id']) ? $orderData['tab_id'] : 
                        (isset($orderData['table_id']) ? $orderData['table_id'] : uniqid())));
        
        // For payment receipts, show like normal receipts
        if (isset($orderData['is_payment_receipt']) && $orderData['is_payment_receipt']) {
            // Show receipt type and number like normal receipts
            $printer->text("Payment Bill Receipt #: " . $receiptNumber . "\n");
            $printer->setEmphasis(false);
        } else if (isset($orderData['table_id']) || isset($orderData['tab_id'])) {
            // For tab sales (not payments), show "KITCHEN ORDER RECEIPT" format
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->text("ORDER!!  No: " . $receiptNumber . "\n");
            $printer->setEmphasis(false);
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            // Show table name
            if (isset($orderData['table_name'])) {
                $tableName = $orderData['table_name'];
                if (strlen($tableName) > 30) {
                    $tableName = substr($tableName, 0, 27) . '...';
                }
                $printer->text("Table : " . $tableName . "\n");
            } else {
                $printer->text("Table : Table " . ($orderData['table_id'] ?? $orderData['tab_id'] ?? 'N/A') . "\n");
            }
            // Show time
            $printer->text("Time : " . date('Y-m-d H:i') . "\n");
            // Show cashier name
            if (isset($orderData['cashier_username'])) {
                $printer->text("By : " . $orderData['cashier_username'] . "\n");
            } else {
                $printer->text("By : Cashier\n");
            }
        } else {
            // Regular receipts
            $receiptType = isset($orderData['sale_id']) ? "Credit Sale" : "Receipt";
            $printer->text($receiptType . " #: " . $receiptNumber . "\n");
            $printer->setEmphasis(false);
        }
        
        // Only show date and order type for non-tab sales
        $isTabSale = isset($orderData['table_id']) || isset($orderData['tab_id']);
        if (!$isTabSale || isset($orderData['is_payment_receipt'])) {
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->text("Date: " . date('Y-m-d H:i') . "\n");
            // Optional order type (dine-in vs takeaway) for restaurant POS
            if (isset($orderData['order_type'])) {
                $typeLabel = strtolower($orderData['order_type']) === 'takeaway' ? 'Takeaway' : 'Dine-in';
                $printer->text("Order: " . $typeLabel . "\n");
            }
            $printer->feed();
            
            // Items section header (only for non-tab sales)
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s %3s %9s\n", "Item", "Qty", "Amount"));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', 42) . "\n");
        } else {
            // For tab sales, add a separator line before items
            $printer->feed();
        }
        
        $subtotal = 0;
        
        // For tab sales, show "ITEMS" header
        if ($isTabSale && !isset($orderData['is_payment_receipt'])) {
            $printer->setEmphasis(true);
            $printer->text("ITEMS\n");
            $printer->setEmphasis(false);
        }
        
        foreach ($orderData['items'] as $item) {
            $name = $item['name'];
            $quantity = $item['quantity'];
            $price = $item['price'] / $quantity;
            $amount = $item['price'];
            $subtotal += $amount;
            
            // For tab sales (kitchen tickets), use simple format: "x1 Ice Tea"
            if ($isTabSale && !isset($orderData['is_payment_receipt'])) {
                // Simple kitchen ticket format
                if (strlen($name) > 35) {
                    $name = substr($name, 0, 32) . '...';
                }
                $printer->text("x" . $quantity . " " . $name . "\n");
            } else {
                // Regular receipt format
                // Print item name (truncate if too long)
                if (strlen($name) > 42) {
                    $name = substr($name, 0, 39) . '...';
                }
                $printer->text($name . "\n");
                
                // Print quantity x price and amount on next line
                $qtyPrice = sprintf("%d x N$%.2f", $quantity, $price);
                $amountText = sprintf("N$%.2f", $amount);
                
                // Ensure proper alignment within 42 characters
                $spaces = 42 - strlen($qtyPrice) - strlen($amountText);
                if ($spaces < 1) $spaces = 1;
                
                $printer->text($qtyPrice . str_repeat(' ', $spaces) . $amountText . "\n");
                $printer->text(str_repeat('-', 42) . "\n");
            }
        }
        
        // Totals section - simplified for tab sales
        $printer->feed();
        $printer->text(str_repeat('-', 42) . "\n");
        
        // For payment receipts, show total and payment info
        if (isset($orderData['is_payment_receipt']) && $orderData['is_payment_receipt']) {
            // Get VAT settings from order data or use defaults
            $paymentVatInclusive = $orderData['vat_inclusive'] ?? $vatInclusive;
            $paymentVatRate = isset($orderData['vat_rate']) ? floatval($orderData['vat_rate']) : $vatRate;
            
            // Calculate VAT for payment receipt
            $vatAmount = 0;
            $displaySubtotal = $subtotal;
            $displayTotal = $subtotal;
            
            if ($paymentVatInclusive === 'exclusive') {
                // Prices exclude VAT, so total is just the subtotal (VAT not included)
                $vatAmount = $subtotal * ($paymentVatRate / 100);
                $displaySubtotal = $subtotal;
                $displayTotal = $subtotal; // Total does not include VAT when VAT is excluded
                
                // Don't show VAT breakdown for exclusive - just show total
            } else {
                // Prices include VAT, so show VAT breakdown
                $vatAmount = $subtotal - ($subtotal / (1 + ($paymentVatRate / 100)));
                $displaySubtotal = $subtotal - $vatAmount;
                $displayTotal = $subtotal;
                
                // Show subtotal (exclusive)
                $printer->text(sprintf("%-20s N$%8.2f\n", "Subtotal (ex VAT):", $displaySubtotal));
                // Show VAT
                $printer->text(sprintf("%-20s N$%8.2f\n", "VAT (" . number_format($paymentVatRate, 2) . "%):", $vatAmount));
                $printer->text(str_repeat('-', 42) . "\n");
            }
            
            // Show total for payment receipt
            $printer->setEmphasis(true);
            $totalText = sprintf("PAID AMOUNT: N$ %8.2f", $displayTotal);
            $spaces = 42 - strlen($totalText);
            $printer->text(str_repeat(' ', $spaces) . $totalText . "\n");
            $printer->setEmphasis(false);
            $printer->feed();
            
            // Payment information section for payment receipts
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->setEmphasis(true);
            $printer->text("PAYMENT INFORMATION\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', 42) . "\n");
            
            // Display payment method details
            if (isset($orderData['payment_method'])) {
                if ($orderData['payment_method'] === 'cash') {
                    $printer->text("Method: Cash\n");
                    if (isset($orderData['cash_received'])) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $orderData['cash_received']));
                        $change = max(0, $orderData['cash_received'] - $displayTotal);
                        if ($change > 0) {
                            $printer->text(sprintf("%-10s N$%8.2f\n", "Change:", $change));
                        }
                    }
                } else if ($orderData['payment_method'] === 'eft') {
                    $printer->text("Method: EFT\n");
                    if (isset($orderData['wallet_provider']) && !empty($orderData['wallet_provider'])) {
                        $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['wallet_provider']));
                    }
                    if (isset($orderData['transaction_ref']) && !empty($orderData['transaction_ref'])) {
                        $ref = $orderData['transaction_ref'];
                        if (strlen($ref) > 30) {
                            $ref = substr($ref, 0, 27) . '...';
                        }
                        $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
                    }
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $displayTotal));
                } else if ($orderData['payment_method'] === 'mixed') {
                    $printer->text("Method: Mixed Payment\n");
                    $printer->text(str_repeat('-', 42) . "\n");
                    
                    // Cash portion
                    if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > 0) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "Cash:", $orderData['cash_amount']));
                    }
                    
                    // EFT portion
                    if (isset($orderData['eft_amount']) && $orderData['eft_amount'] > 0) {
                        $printer->text(sprintf("%-10s N$%8.2f\n", "EFT:", $orderData['eft_amount']));
                        if (isset($orderData['eft_wallet_provider']) && !empty($orderData['eft_wallet_provider'])) {
                            $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['eft_wallet_provider']));
                        }
                        if (isset($orderData['eft_transaction_ref']) && !empty($orderData['eft_transaction_ref'])) {
                            $ref = $orderData['eft_transaction_ref'];
                            if (strlen($ref) > 30) {
                                $ref = substr($ref, 0, 27) . '...';
                            }
                            $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
                        }
                    }
                    
                    $printer->text(str_repeat('-', 42) . "\n");
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Total:", $displayTotal));
                }
            }
            $printer->feed();
        } else if (isset($orderData['table_id']) || isset($orderData['tab_id'])) {
            // Kitchen ticket format - show "SEND TO KITCHEN" message
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->setEmphasis(false);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
        } else {
            // Regular receipts with VAT breakdown
            // Calculate VAT based on settings
            $vatAmount = 0;
            $displaySubtotal = $subtotal;
            $displayTotal = $subtotal;
            
            if ($vatInclusive === 'exclusive') {
                // Prices exclude VAT, so total is just the subtotal (VAT not included)
                $vatAmount = $subtotal * ($vatRate / 100);
                $displaySubtotal = $subtotal;
                $displayTotal = $subtotal; // Total does not include VAT when VAT is excluded
                
                // Don't show VAT breakdown for exclusive - just show total
            } else {
                // Prices include VAT, so show VAT breakdown
                $vatAmount = $subtotal - ($subtotal / (1 + ($vatRate / 100)));
                $displaySubtotal = $subtotal - $vatAmount;
                $displayTotal = $subtotal;
                
                // Show subtotal (exclusive)
                $printer->text(sprintf("%-20s N$%8.2f\n", "Subtotal (ex VAT):", $displaySubtotal));
                // Show VAT
                $printer->text(sprintf("%-20s N$%8.2f\n", "VAT (" . number_format($vatRate, 2) . "%):", $vatAmount));
                $printer->text(str_repeat('-', 42) . "\n");
            }
            
            // Show total
            $printer->setEmphasis(true);
            $totalText = sprintf("TOTAL: N$ %8.2f", $displayTotal);
            $spaces = 42 - strlen($totalText);
            $printer->text(str_repeat(' ', $spaces) . $totalText . "\n");
            $printer->setEmphasis(false);
            $printer->feed();
            
            // Payment information section (for non-tab sales and payment receipts)
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->setEmphasis(true);
            $printer->text("PAYMENT INFORMATION\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', 42) . "\n");
            
            if (isset($orderData['creditor_id'])) {
                // Credit payment
            $printer->text("Method: Credit\n");
            $printer->text(sprintf("%-10s %s\n", "ID:", $orderData['creditor_id']));
            if (isset($orderData['creditor_name'])) {
                $printer->text(sprintf("%-10s %s\n", "Name:", $orderData['creditor_name']));
            }
            if (isset($orderData['due_date'])) {
                $printer->text(sprintf("%-10s %s\n", "Due:", $orderData['due_date']));
            }
            // Show partial payment info if not fully paid
            if (isset($orderData['payment_type']) && $orderData['payment_type'] === 'cash' && isset($orderData['cash_received']) && isset($orderData['total_amount'])) {
                if ($orderData['cash_received'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $orderData['cash_received']));
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $orderData['total_amount'] - $orderData['cash_received']));
                }
            }
            if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'e-wallet' && isset($orderData['payment_amount']) && isset($orderData['total_amount'])) {
                if ($orderData['payment_amount'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment (EFT)\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $orderData['payment_amount']));
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $orderData['total_amount'] - $orderData['payment_amount']));
                }
            }
            $printer->feed();
            // Add barcode for creditor ID ONLY for credit sales - IMPROVED BARCODE LOGIC
            if (isset($orderData['creditor_id'])) {
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                $printer->text("Creditor ID:\n");
                $printer->feed(1);
                
                $barcodePrinted = false;
                $barcodeFormat = 'none';
                
                // Prepare creditor ID for barcode (ensure it's clean and valid)
                $barcodeData = trim(strval($orderData['creditor_id']));
                
                // Ensure we have valid data
                if (empty($barcodeData)) {
                    $barcodeData = "CRED" . $orderData['creditor_id'];
                }
                
                // Initialize printer for barcode printing
                $printer->initialize();
                
                // Use CODE39 barcode (based on the working example)
                try {
                    // Set barcode parameters for better visibility
                    $printer->setBarcodeHeight(80);        // Increased height for better visibility
                    $printer->setBarcodeWidth(3);          // Increased width for better scanning
                    $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_NONE); // Don't show text below barcode
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    
                    // CODE39 format: Add asterisks around the data (as shown in example)
                    // For pure numbers, add a prefix internally for barcode generation only
                    if (is_numeric($barcodeData)) {
                        $code39Data = "*CRED" . $barcodeData . "*";
                    } else {
                        $code39Data = "*" . strtoupper($barcodeData) . "*";
                    }
                    
                    // Print CODE39 barcode (works with both text and numbers)
                    $printer->barcode($code39Data, Printer::BARCODE_CODE39);
                    
                    // Manually print the creditor ID below the barcode
                    $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_NONE);
                    $printer->text("\n" . $barcodeData . "\n");
                    $printer->feed(2);
                    
                    $barcodePrinted = true;
                    $barcodeFormat = 'CODE39';
                    
                } catch (Exception $e) {
                    error_log("CODE39 barcode failed with error: " . $e->getMessage());
                    
                    // Try CODE128 as fallback
                    try {
                        error_log("Trying CODE128 as fallback...");
                        
                        $printer->initialize();
                        $printer->setBarcodeHeight(80);
                        $printer->setBarcodeWidth(3);
                        $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_BELOW);
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        
                        $printer->barcode($barcodeData, Printer::BARCODE_CODE128);
                        $printer->feed(2);
                        
                        $barcodePrinted = true;
                        $barcodeFormat = 'CODE128';
                        error_log("CODE128 barcode printed successfully for creditor ID: " . $orderData['creditor_id']);
                        
                    } catch (Exception $e2) {
                        error_log("CODE128 fallback also failed: " . $e2->getMessage());
                    }
                }
                
                // If all barcode formats failed, print as large text with manual number display
                if (!$barcodePrinted) {
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->text("*** CREDITOR ID ***\n");
                    $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
                    $printer->text($orderData['creditor_id'] . "\n");
                    $printer->selectPrintMode();
                    $printer->text("*** SCAN CODE ***\n");
                    
                    $barcodeFormat = 'text_fallback';
                    error_log("All barcode formats failed, using text fallback for creditor ID: " . $orderData['creditor_id']);
                }
                
                $printer->setJustification(Printer::JUSTIFY_LEFT); // Reset alignment
            }
        } else if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'e-wallet') {
            // E-wallet payment
            $printer->text("Method: EFT\n");
            $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['wallet_provider']));
            $ref = $orderData['transaction_ref'];
            if (strlen($ref) > 30) {
                $ref = substr($ref, 0, 27) . '...';
            }
            $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
            $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $subtotal));
        } else if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'mixed') {
            // Mixed payment (Cash + EFT)
            $printer->text("Method: Mixed Payment\n");
            $printer->text(str_repeat('-', 42) . "\n");
            
            // Cash portion
            if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > 0) {
                $printer->text(sprintf("%-10s N$%8.2f\n", "Cash:", $orderData['cash_amount']));
            }
            
            // EFT portion
            if (isset($orderData['eft_amount']) && $orderData['eft_amount'] > 0) {
                $printer->text(sprintf("%-10s N$%8.2f\n", "EFT:", $orderData['eft_amount']));
                if (isset($orderData['wallet_provider'])) {
                    $printer->text(sprintf("%-10s %s\n", "Provider:", $orderData['wallet_provider']));
                }
                if (isset($orderData['transaction_ref']) && !empty($orderData['transaction_ref'])) {
                    $ref = $orderData['transaction_ref'];
                    if (strlen($ref) > 30) {
                        $ref = substr($ref, 0, 27) . '...';
                    }
                    $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
                }
            }
            
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->text(sprintf("%-10s N$%8.2f\n", "Total:", $subtotal));
            
            // Calculate and show change if cash amount is greater than total
            if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > $subtotal) {
                $change = max(0, $orderData['cash_amount'] - $subtotal);
                $changeText = sprintf("Change: %10s", "N$ " . number_format($change, 2));
                $printer->text($changeText . "\n");
            }
        } else {
            // Cash payment
            $printer->text("Method: Cash\n");
            $paidText = sprintf("Paid: %10s", "N$ " . number_format($orderData['cash_received'], 2));
            $printer->text($paidText . "\n");
            $change = max(0, $orderData['cash_received'] - $subtotal);
            $changeText = sprintf("Change: %8s", "N$ " . number_format($change, 2));
            $printer->text($changeText . "\n");
            }
        }
        
        $printer->feed();
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->feed();
        
        // Footer section - skip for kitchen tickets (tab sales)
        if (!$isTabSale || $isPaymentReceipt) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text($businessInfo['footer_text'] . "\n");
            $printer->feed(4);
        }
    }

    // Send final commands
    try {
        $connector->write(chr(27).chr(109));
        $printer->cut();
        
        // Close the printer connection
        $printer->close();
    } catch (Exception $e) {
        throw new Exception("Failed to complete printing: " . $e->getMessage());
    }

    // Return success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Receipt printed successfully',
        'printer_used' => $printerName,
        'client_ip' => $clientIP,
        'connection_type' => $isNetworkPrinter ? 'network' : 'local'
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'printer_attempted' => $printerName ?? 'unknown',
        'client_ip' => $clientIP ?? 'unknown',
        'connection_type' => ($isNetworkPrinter ?? false) ? 'network' : 'local',
        'details' => 'Please check if the printer is connected and powered on.'
    ]);
}
exit;
?>