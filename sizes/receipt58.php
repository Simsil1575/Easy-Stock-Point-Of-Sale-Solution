<?php
// Suppress warnings and notices, and clear output buffer
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
if (ob_get_level()) ob_clean();
/* Call this file 'hello-world.php' */
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../receipt_payment_helper.php';
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
                'printer_port' => 'COM4'
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
        // Print cash-up receipt (32-char format for 58mm)
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        $printer->text($businessInfo['location'] . "\n");
        $printer->setEmphasis(false);
        $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->setEmphasis(true);
        $printer->text("DAILY CASH-UP REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Printed: " . date('Y-m-d H:i') . "\n");
        $printer->text("By: " . $orderData['cashier_username'] . "\n");
        $printer->feed();
        $printer->text(str_repeat('=', 32) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s%11s\n", "EXPECTED CASH:", "N$" . number_format($calculatedExpectedCash, 2)));
        $printer->setEmphasis(false);
        
        // Add actual cash and difference if provided
        if (isset($orderData['actual_cash_in_till'])) {
            $printer->text(sprintf("%-20s%11s\n", "ACTUAL CASH:", "N$" . number_format($orderData['actual_cash_in_till'], 2)));
            if (isset($orderData['cash_difference'])) {
                $difference = floatval($orderData['cash_difference']);
                if ($difference != 0) {
                    $printer->text(sprintf("%-20s%11s\n", 
                        $difference > 0 ? "SURPLUS:" : "SHORTAGE:", 
                        "N$" . number_format(abs($difference), 2)));
                }
            }
        }
        $printer->text(str_repeat('=', 32) . "\n");
        $printer->feed();
        // Income/Expense breakdown if available
        if (isset($orderData['total_income'])) {
            $printer->setEmphasis(true);
            $printer->text("INCOME AND EXPENSES\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->text(sprintf("%-20s%11s\n", "CASH:", "N$" . number_format($orderData['cash_sales'] + ($orderData['credit_cash'] ?? 0), 2)));
            $printer->text(sprintf("%-20s%11s\n", "EFT:", "N$" . number_format(($orderData['credit_eft'] ?? 0) + ($orderData['eft_sales'] ?? 0), 2)));
            $printer->text(sprintf("%-20s%11s\n", "CREDIT:", "N$" . number_format($orderData['credit_unpaid'] ?? 0, 2)));
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s%11s\n", "TOTAL INCOME:", "N$" . number_format($orderData['total_income'] ?? 0, 2)));
            $printer->setEmphasis(false);
            $printer->text(sprintf("%-20s%11s\n", "TOTAL EXPENSES:", "N$" . number_format($orderData['total_expense'] ?? 0, 2)));
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s%11s\n", "NET AMOUNT:", "N$" . number_format($orderData['net_amount'] ?? 0, 2)));
            $printer->setEmphasis(false);
        }
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(2);
        $printer->text("____________________\n");
        $printer->text("Cashier Signature\n");
        $printer->feed(2);
        $printer->text("____________________\n");
        $printer->text("Manager Signature\n");
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
            'printer_port' => 'COM4'
        ];
    }
    
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

    // Store header with emphasis
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

    // Check if this is a cash-up report or regular receipt
    if (isset($orderData['is_cashup_report']) && $orderData['is_cashup_report']) {
        // CASH-UP REPORT PRINTING (32 chars for 58mm)
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->setEmphasis(true);
        $printer->text("DAILY CASH-UP REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Printed: " . date('Y-m-d H:i') . "\n");
        $printer->text("By: " . $orderData['cashier_username'] . "\n");
        $printer->feed();
        $printer->text(str_repeat('=', 32) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        // This is a duplicate cash-up section - should not be reached since cash-up is handled above
        // But keeping it for backwards compatibility with old data structure
        $cashSales = $orderData['total_cash_sales'] ?? 0;
        $creditSales = $orderData['credit_sales_total'] ?? 0;
        $unpaidCredit = $orderData['unpaid_credit'] ?? 0;
        $expectedCash = $orderData['cash_available_in_till'] ?? 0;
        $printer->text(sprintf("%-20s%11s\n", "CASH SALES:", "N$" . number_format($cashSales, 2)));
        $printer->text(sprintf("%-20s%11s\n", "CREDIT SALES:", "N$" . number_format($creditSales, 2)));
        $printer->text(sprintf("%-20s%11s\n", "UNPAID:", "N$" . number_format($unpaidCredit, 2)));
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s%11s\n", "EXPECTED CASH:", "N$" . number_format($expectedCash, 2)));
        $printer->setEmphasis(false);
        
        // Add actual cash and difference if provided
        if (isset($orderData['actual_cash_in_till'])) {
            $actualCash = floatval($orderData['actual_cash_in_till']);
            $printer->text(sprintf("%-20s%11s\n", "ACTUAL CASH:", "N$" . number_format($actualCash, 2)));
            
            // Calculate difference using the passed expected cash amount
            $difference = $actualCash - $expectedCash;
            if ($difference != 0) {
                $printer->text(sprintf("%-20s%11s\n", 
                    $difference > 0 ? "SURPLUS:" : "SHORTAGE:", 
                    "N$" . number_format(abs($difference), 2)));
            }
        }
        $printer->text(str_repeat('=', 32) . "\n");
        $printer->feed();
        // Signature line
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->text("Signature: ______________\n");
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*** End of Report ***\n");
        $printer->feed();
    } else if (isset($orderData['is_balance_receipt']) && $orderData['is_balance_receipt']) {
        // Check if this is a tab balance receipt or credit sale balance receipt
        if (isset($orderData['is_tab_balance_receipt']) && $orderData['is_tab_balance_receipt']) {
            // TAB BALANCE RECEIPT - 32 chars for 58mm
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("TAB BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->text(sprintf("Tab: %s\n", $orderData['tab_name'] ?? 'N/A'));
            if (isset($orderData['creditor_name']) && $orderData['creditor_name'] !== 'N/A') {
                $printer->text(sprintf("Client: %s\n", $orderData['creditor_name']));
            }
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->feed();
            
            // Print outstanding items
            if (isset($orderData['items']) && !empty($orderData['items'])) {
                $printer->setEmphasis(true);
                $printer->text("OUTSTANDING ITEMS\n");
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', 32) . "\n");
                
                $itemsTotal = 0;
                foreach ($orderData['items'] as $item) {
                    $itemName = $item['name'];
                    if (strlen($itemName) > 32) {
                        $itemName = substr($itemName, 0, 29) . '...';
                    }
                    $qty = intval($item['quantity']);
                    $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : (floatval($item['price']) / $qty);
                    $itemTotal = floatval($item['price']);
                    $itemsTotal += $itemTotal;
                    
                    $printer->text($itemName . "\n");
                    $printer->text(sprintf(" %dx N$%.2f = N$%.2f\n", $qty, $unitPrice, $itemTotal));
                }
                $printer->text(str_repeat('-', 32) . "\n");
                $printer->setEmphasis(true);
                $printer->text(sprintf("%-20s%11s\n", "ITEMS TOTAL:", "N$" . number_format($itemsTotal, 2)));
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', 32) . "\n");
                $printer->feed();
            }
            
            // Print total balance
            $printer->setEmphasis(true);
            $printer->text(str_repeat('=', 32) . "\n");
            $printer->text(sprintf("%-20s%11s\n", "OUTSTANDING:", "N$" . number_format($orderData['total_balance'], 2)));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('=', 32) . "\n");
            $printer->feed(2);
        } else {
            // CREDIT SALE BALANCE RECEIPT - 32 chars for 58mm
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $printer->text("BALANCE RECEIPT\n");
            $printer->selectPrintMode();
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->text(sprintf("Client: %s\n", $orderData['creditor_name']));
            $printer->text(sprintf("%-20s%11s\n", "Balance:", "N$" . number_format($orderData['total_balance'], 2)));
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->feed();
            
            // Print transaction details
            if (isset($orderData['transactions']) && !empty($orderData['transactions'])) {
                $printer->setEmphasis(true);
                $printer->text("OUTSTANDING ITEMS\n");
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', 32) . "\n");
                
                foreach ($orderData['transactions'] as $transaction) {
                    $printer->text("Date: " . date('Y-m-d', strtotime($transaction['date'])) . "\n");
                    $printer->text(str_repeat('-', 32) . "\n");
                    
                    // Split items string into individual items
                    $items = explode(', ', $transaction['items']);
                    foreach ($items as $item) {
                        // Truncate item name if too long
                        if (strlen($item) > 32) {
                            $item = substr($item, 0, 29) . '...';
                        }
                        $printer->text($item . "\n");
                    }
                    
                    $printer->text(sprintf("%-20s%11s\n", "Balance:", "N$" . number_format($transaction['balance'], 2)));
                    $printer->text(str_repeat('-', 32) . "\n");
                    $printer->feed();
                }
            }
            
            // Print total balance again
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-20s%11s\n", "TOTAL BALANCE:", "N$" . number_format($orderData['total_balance'], 2)));
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->feed(2);
        }
        
        // Footer section
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(4);
        
        // Add a line of dashes before cutting
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->feed(2);
    } else {
        // REGULAR RECEIPT PRINTING - 32 chars for 58mm
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->setEmphasis(true);
        $receiptNumber = isset($orderData['order_id']) ? $orderData['order_id'] : (isset($orderData['sale_id']) ? $orderData['sale_id'] : uniqid());
        $receiptType = isset($orderData['sale_id']) ? "Credit Sale" : "Receipt";
        $printer->text($receiptType . " #: " . $receiptNumber . "\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->text("Date: " . date('Y-m-d H:i') . "\n");
        $printer->feed();
        
        // Items section header
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-18s %3s %8s\n", "Item", "Qty", "Amount"));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 32) . "\n");
        
        $subtotal = 0;
        foreach ($orderData['items'] as $item) {
            $name = $item['name'];
            $quantity = $item['quantity'];
            $price = $item['price'] / $quantity;
            $amount = $item['price'];
            $subtotal += $amount;
            
            // Print item name (truncate if too long)
            if (strlen($name) > 32) {
                $name = substr($name, 0, 29) . '...';
            }
            $printer->text($name . "\n");
            
            // Print quantity x price and amount on next line
            $qtyPrice = sprintf("%dx N$%.2f", $quantity, $price);
            $amountText = sprintf("N$%.2f", $amount);
            
            // Ensure proper alignment within 32 characters
            $spaces = 32 - strlen($qtyPrice) - strlen($amountText);
            if ($spaces < 1) $spaces = 1;
            
            $printer->text($qtyPrice . str_repeat(' ', $spaces) . $amountText . "\n");
            $printer->text(str_repeat('-', 32) . "\n");
        }
        
        // Totals section
        $printer->feed();
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s%11s\n", "TOTAL:", "N$" . number_format($subtotal, 2)));
        $printer->setEmphasis(false);
        $printer->feed();
        
        // Payment information section
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->setEmphasis(true);
        $printer->text("PAYMENT INFORMATION\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 32) . "\n");
        
        if (isset($orderData['creditor_id'])) {
            // Credit payment
            $printer->text("Method: Credit\n");
            $printer->text(sprintf("ID: %s\n", $orderData['creditor_id']));
            if (isset($orderData['creditor_name'])) {
                $printer->text(sprintf("Name: %s\n", $orderData['creditor_name']));
            }
            if (isset($orderData['due_date'])) {
                $printer->text(sprintf("Due: %s\n", $orderData['due_date']));
            }
            // Show partial payment info if not fully paid
            if (isset($orderData['payment_type']) && $orderData['payment_type'] === 'cash' && isset($orderData['cash_received']) && isset($orderData['total_amount'])) {
                if ($orderData['cash_received'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-20s%11s\n", "Paid:", "N$" . number_format($orderData['cash_received'], 2)));
                    $printer->text(sprintf("%-20s%11s\n", "Balance:", "N$" . number_format($orderData['total_amount'] - $orderData['cash_received'], 2)));
                }
            }
            if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'e-wallet' && isset($orderData['payment_amount']) && isset($orderData['total_amount'])) {
                if ($orderData['payment_amount'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment (EFT)\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-20s%11s\n", "Paid:", "N$" . number_format($orderData['payment_amount'], 2)));
                    $printer->text(sprintf("%-20s%11s\n", "Balance:", "N$" . number_format($orderData['total_amount'] - $orderData['payment_amount'], 2)));
                }
            }
            $printer->feed();
            // Add barcode for transaction ID ONLY for credit sales
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Transaction ID:\n");
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT);
            $printer->barcode($receiptNumber, Printer::BARCODE_CODE39);
            $printer->selectPrintMode(); // Reset print mode
            $printer->feed();
        } else if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'e-wallet') {
            // E-wallet payment
            $printer->text("Method: EFT\n");
            $printer->text(sprintf("Provider: %s\n", $orderData['wallet_provider']));
            $ref = $orderData['transaction_ref'];
            if (strlen($ref) > 20) {
                $ref = substr($ref, 0, 17) . '...';
            }
            $printer->text(sprintf("Ref: %s\n", $ref));
            $printer->text(sprintf("%-20s%11s\n", "Paid:", "N$" . number_format($subtotal, 2)));
        } else if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'mixed') {
            // Mixed payment (Cash + EFT)
            $printer->text("Method: Mixed Payment\n");
            $printer->text(str_repeat('-', 32) . "\n");
            
            // Cash portion
            if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > 0) {
                $printer->text(sprintf("%-20s%11s\n", "Cash:", "N$" . number_format($orderData['cash_amount'], 2)));
            }
            
            // EFT portion
            if (isset($orderData['eft_amount']) && $orderData['eft_amount'] > 0) {
                $printer->text(sprintf("%-20s%11s\n", "EFT:", "N$" . number_format($orderData['eft_amount'], 2)));
                if (isset($orderData['wallet_provider'])) {
                    $printer->text(sprintf("Provider: %s\n", $orderData['wallet_provider']));
                }
                if (isset($orderData['transaction_ref']) && !empty($orderData['transaction_ref'])) {
                    $ref = $orderData['transaction_ref'];
                    if (strlen($ref) > 20) {
                        $ref = substr($ref, 0, 17) . '...';
                    }
                    $printer->text(sprintf("Ref: %s\n", $ref));
                }
            }
            
            $printer->text(str_repeat('-', 32) . "\n");
            $printer->text(sprintf("%-20s%11s\n", "Total:", "N$" . number_format($subtotal, 2)));
            
            $mixedChange = receipt_mixed_payment_change($orderData, $subtotal);
            if ($mixedChange > 0.004) {
                $printer->text(sprintf("%-20s%11s\n", "Change:", "N$" . number_format($mixedChange, 2)));
            }
        } else if (isset($orderData['table_id']) || isset($orderData['tab_id'])) {
            // Tab sale - no payment received
            $printer->text("Method: Tab\n");
            if (isset($orderData['table_name'])) {
                $tableName = $orderData['table_name'];
                if (strlen($tableName) > 20) {
                    $tableName = substr($tableName, 0, 17) . '...';
                }
                $printer->text(sprintf("Table: %s\n", $tableName));
            }
            $printer->text(sprintf("%-20s%11s\n", "Total:", "N$" . number_format($subtotal, 2)));
            // No change for tab sales
        } else {
            // Cash payment
            $printer->text("Method: Cash\n");
            $printer->text(sprintf("%-20s%11s\n", "Paid:", "N$" . number_format($orderData['cash_received'], 2)));
            $change = max(0, $orderData['cash_received'] - $subtotal);
            $printer->text(sprintf("%-20s%11s\n", "Change:", "N$" . number_format($change, 2)));
        }
        
        $printer->feed();
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->feed();
        
        // Footer section
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(4);
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