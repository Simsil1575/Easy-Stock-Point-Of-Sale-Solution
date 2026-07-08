
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

// Handle cash-up receipt printing
if (isset($orderData['is_cashup_report']) && $orderData['is_cashup_report']) {
    try {
        // Set timezone to Namibia
        date_default_timezone_set('Africa/Harare');

        // Connect to database and get business info
        $db = new PDO('sqlite:info.db');
        $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$businessInfo) {
            $businessInfo = [
                'name' => 'POS SOLUTION',
                'location' => 'Your Business Address',
                'phone' => 'Your Phone Number',
                'footer_text' => 'Thank you for your purchase!',
                'printer_port' => 'COM4'
            ];
        }
        // Printer selection logic (same as before)
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
        // Create printer connection
        if ($isNetworkPrinter) {
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            $connector = new WindowsPrintConnector($printerName);
        }
        $printer = new Printer($connector);
        // Print cash-up receipt (48-char format)
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        $printer->text($businessInfo['location'] . "\n");
        $printer->setEmphasis(false);
        $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        // Removed feed() - separator line comes next
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->setEmphasis(true);
        $printer->text("DAILY CASH-UP REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Printed: " . date('Y-m-d H:i') . "\n");
        $printer->text("By: " . $orderData['cashier_username'] . "\n");
        // Removed feed() - totals section comes next
        $printer->text(str_repeat('=', 48) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(sprintf("%-24s N$%8.2f\n", "CASH SALES:", $orderData['total_cash_sales']));
        $printer->text(sprintf("%-24s N$%8.2f\n", "EFT SALES:", $orderData['eft_sales_total']));
        $printer->text(sprintf("%-24s N$%8.2f\n", "UNPAID CREDIT:", $orderData['unpaid_credit']));
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-24s N$%8.2f\n", "CASH ON HAND:", $orderData['cash_on_hand']));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('=', 48) . "\n");
        // Removed feed() - income/expense section comes next
        // Income/Expense breakdown if available
        if (isset($orderData['total_income'])) {
            $printer->setEmphasis(true);
            $printer->text("INCOME AND EXPENSES\n");
            $printer->setEmphasis(false);
            $printer->text(str_repeat('-', 48) . "\n");
            $printer->text(sprintf("%-24s N$%8.2f\n", "CASH SALES:", $orderData['cash_sales'] ?? 0));
            $printer->text(sprintf("%-24s N$%8.2f\n", "CREDIT (CASH):", $orderData['credit_cash'] ?? 0));
            $printer->text(sprintf("%-24s N$%8.2f\n", "CREDIT (EFT):", $orderData['credit_eft'] ?? 0));
            $printer->text(sprintf("%-24s N$%8.2f\n", "EFT SALES:", $orderData['eft_sales'] ?? 0));
            $printer->text(sprintf("%-24s N$%8.2f\n", "UNPAID CREDIT:", $orderData['credit_unpaid'] ?? 0));
            $printer->text(str_repeat('-', 48) . "\n");
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-24s N$%8.2f\n", "TOTAL INCOME:", $orderData['total_income'] ?? 0));
            $printer->setEmphasis(false);
            $printer->text(sprintf("%-24s N$%8.2f\n", "TOTAL EXPENSES:", $orderData['total_expense'] ?? 0));
            $printer->text(str_repeat('-', 48) . "\n");
            $printer->setEmphasis(true);
            $printer->text(sprintf("%-24s N$%8.2f\n", "NET AMOUNT:", $orderData['net_amount'] ?? 0));
            $printer->setEmphasis(false);
        }
        // Removed feed() - footer comes directly
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        // Reduced from feed(2) to feed(1) to save paper
        $printer->feed(1);
        $printer->text("____________________\n");
        $printer->text("Cashier Signature\n");
        // Removed feed() - manager signature comes next
        $printer->text("____________________\n");
        $printer->text("Manager Signature\n");
        // Removed feed() - cut immediately to save paper
        $printer->cut();
        $printer->pulse();
        $printer->close();
        
        // Enrich orderData with business info before returning
        $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
        $orderData['location'] = $businessInfo['location'] ?? '';
        $orderData['phone'] = $businessInfo['phone'] ?? '';
        $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
        $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
        $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Cash-up receipt printed',
            'order_data' => $orderData  // Include enriched orderData for Android
        ]);
        exit;
    } catch (Exception $e) {
        // Enrich orderData even on error for Android compatibility
        if (isset($orderData) && is_array($orderData)) {
            try {
                $db = new PDO('sqlite:info.db');
                $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($businessInfo) {
                    $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
                    $orderData['location'] = $businessInfo['location'] ?? '';
                    $orderData['phone'] = $businessInfo['phone'] ?? '';
                    $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
                    $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
                    $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
                }
            } catch (Exception $dbError) {
                // Ignore database errors in error handler
            }
        }
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'order_data' => $orderData ?? []  // Include orderData even on error for Android
        ]);
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
            'printer_port' => 'COM4',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    
    // Enrich orderData with business info for Android interceptor
    $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
    $orderData['location'] = $businessInfo['location'] ?? '';
    $orderData['phone'] = $businessInfo['phone'] ?? '';
    $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
    $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
    
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
        // CASH-UP REPORT PRINTING
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->setEmphasis(true);
        $printer->text("DAILY CASH-UP REPORT\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Printed: " . date('Y-m-d H:i') . "\n");
        $printer->text("By: " . $orderData['cashier_username'] . "\n");
        $printer->feed();
        $printer->text(str_repeat('=', 48) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        // Format and print cash-up summary for 48 chars
        $cashSales = $orderData['total_cash_sales'];
        $creditSales = $orderData['credit_sales_total'];
        $unpaidCredit = $orderData['unpaid_credit'];
        $cashOnHand = $orderData['cash_on_hand'];
        $printer->text(sprintf("%-24s N$%8.2f\n", "CASH SALES:", $cashSales));
        $printer->text(sprintf("%-24s N$%8.2f\n", "CREDIT SALES:", $creditSales));
        $printer->text(sprintf("%-24s N$%8.2f\n", "UNPAID:", $unpaidCredit));
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-24s N$%8.2f\n", "CASH ON HAND:", $cashOnHand));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('=', 48) . "\n");
        $printer->feed();
        // Signature line
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->text("Signature: ________________\n");
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*** End of Cash-up Report ***\n");
        $printer->feed();
    } else if (isset($orderData['is_balance_receipt']) && $orderData['is_balance_receipt']) {
        // Print total balance receipt - 48 chars
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
        $printer->text("BALANCE RECEIPT\n");
        $printer->selectPrintMode();
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->text(sprintf("%-12s %s\n", "Client:", $orderData['creditor_name']));
        $printer->text(sprintf("%-12s N$%8.2f\n", "Balance:", $orderData['total_balance']));
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->feed();
        
        // Print transaction details
        $printer->setEmphasis(true);
        $printer->text("OUTSTANDING ITEMS\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 48) . "\n");
        
        foreach ($orderData['transactions'] as $transaction) {
            $printer->text("Date: " . date('Y-m-d', strtotime($transaction['date'])) . "\n");
            $printer->text(str_repeat('-', 48) . "\n");
            
            // Split items string into individual items
            $items = explode(', ', $transaction['items']);
            foreach ($items as $item) {
                // Truncate item name if too long
                if (strlen($item) > 48) {
                    $item = substr($item, 0, 45) . '...';
                }
                $printer->text($item . "\n");
            }
            
            $printer->text(sprintf("%-24s N$%8.2f\n", "Balance:", $transaction['balance']));
            $printer->text(str_repeat('-', 48) . "\n");
            // Removed feed() - total balance comes next
        }
        
        // Print total balance again
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-24s N$%8.2f\n", "TOTAL BALANCE:", $orderData['total_balance']));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 48) . "\n");
        // Footer section
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        // Removed feed() and dashes - cut immediately to save paper
        // Reduced from feed(2) to feed(1) to save paper
        $printer->feed(1);
    } else {
        // REGULAR RECEIPT PRINTING - 48 chars
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->setEmphasis(true);
        $receiptNumber = isset($orderData['order_id']) ? $orderData['order_id'] : (isset($orderData['sale_id']) ? $orderData['sale_id'] : uniqid());
        $receiptType = isset($orderData['sale_id']) ? "Credit Sale" : "Receipt";
        $printer->text($receiptType . " #: " . $receiptNumber . "\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->text("Date: " . date('Y-m-d H:i') . "\n");
        $printer->feed();
        
        // Items section header
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-24s %3s %9s\n", "Item", "Qty", "Amount"));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 48) . "\n");
        
        $subtotal = 0;
        foreach ($orderData['items'] as $item) {
            $name = $item['name'];
            $quantity = $item['quantity'];
            $price = $item['price'] / $quantity;
            $amount = $item['price'];
            $subtotal += $amount;
            
            // Print item name (truncate if too long)
            if (strlen($name) > 48) {
                $name = substr($name, 0, 45) . '...';
            }
            $printer->text($name . "\n");
            
            // Print quantity x price and amount on next line
            $qtyPrice = sprintf("%d x N$%.2f", $quantity, $price);
            $amountText = sprintf("N$%.2f", $amount);
            
            // Ensure proper alignment within 48 characters
            $spaces = 48 - strlen($qtyPrice) - strlen($amountText);
            if ($spaces < 1) $spaces = 1;
            
            $printer->text($qtyPrice . str_repeat(' ', $spaces) . $amountText . "\n");
            $printer->text(str_repeat('-', 48) . "\n");
        }
        
        // Totals section
        $printer->feed();
        $printer->setEmphasis(true);
        $totalText = sprintf("TOTAL: N$ %8.2f", $subtotal);
        $spaces = 48 - strlen($totalText);
        $printer->text(str_repeat(' ', $spaces) . $totalText . "\n");
        $printer->setEmphasis(false);
        $printer->feed();
        
        // Payment information section
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->setEmphasis(true);
        $printer->text("PAYMENT INFORMATION\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 48) . "\n");
        
        if (isset($orderData['creditor_id'])) {
            // Credit payment
            $printer->text("Method: Credit\n");
            $printer->text(sprintf("%-12s %s\n", "ID:", $orderData['creditor_id']));
            if (isset($orderData['creditor_name'])) {
                $printer->text(sprintf("%-12s %s\n", "Name:", $orderData['creditor_name']));
            }
            if (isset($orderData['due_date'])) {
                $printer->text(sprintf("%-12s %s\n", "Due:", $orderData['due_date']));
            }
            // Show partial payment info if not fully paid
            if (isset($orderData['payment_type']) && $orderData['payment_type'] === 'cash' && isset($orderData['cash_received']) && isset($orderData['total_amount'])) {
                if ($orderData['cash_received'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-12s N$%8.2f\n", "Paid:", $orderData['cash_received']));
                    $printer->text(sprintf("%-12s N$%8.2f\n", "Balance:", $orderData['total_amount'] - $orderData['cash_received']));
                }
            }
            if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'e-wallet' && isset($orderData['payment_amount']) && isset($orderData['total_amount'])) {
                if ($orderData['payment_amount'] < $orderData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment (EFT)\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-12s N$%8.2f\n", "Paid:", $orderData['payment_amount']));
                    $printer->text(sprintf("%-12s N$%8.2f\n", "Balance:", $orderData['total_amount'] - $orderData['payment_amount']));
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
            $printer->text(sprintf("%-12s %s\n", "Provider:", $orderData['wallet_provider']));
            $ref = $orderData['transaction_ref'];
            if (strlen($ref) > 36) {
                $ref = substr($ref, 0, 33) . '...';
            }
            $printer->text(sprintf("%-12s %s\n", "Ref:", $ref));
            $printer->text(sprintf("%-12s N$%8.2f\n", "Paid:", $subtotal));
        } else if (isset($orderData['payment_method']) && $orderData['payment_method'] === 'mixed') {
            // Mixed payment (Cash + EFT)
            $printer->text("Method: Mixed Payment\n");
            $printer->text(str_repeat('-', 48) . "\n");
            
            // Cash portion
            if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > 0) {
                $printer->text(sprintf("%-12s N$%8.2f\n", "Cash:", $orderData['cash_amount']));
            }
            
            // EFT portion
            if (isset($orderData['eft_amount']) && $orderData['eft_amount'] > 0) {
                $printer->text(sprintf("%-12s N$%8.2f\n", "EFT:", $orderData['eft_amount']));
                if (isset($orderData['wallet_provider'])) {
                    $printer->text(sprintf("%-12s %s\n", "Provider:", $orderData['wallet_provider']));
                }
                if (isset($orderData['transaction_ref']) && !empty($orderData['transaction_ref'])) {
                    $ref = $orderData['transaction_ref'];
                    if (strlen($ref) > 36) {
                        $ref = substr($ref, 0, 33) . '...';
                    }
                    $printer->text(sprintf("%-12s %s\n", "Ref:", $ref));
                }
            }
            
            $printer->text(str_repeat('-', 48) . "\n");
            $printer->text(sprintf("%-12s N$%8.2f\n", "Total:", $subtotal));
            
            // Calculate and show change if cash amount is greater than total
            if (isset($orderData['cash_amount']) && $orderData['cash_amount'] > $subtotal) {
                $change = $orderData['cash_amount'] - $subtotal;
                $changeText = sprintf("Change: %10s", "N$ " . number_format($change, 2));
                $printer->text($changeText . "\n");
            }
        } else {
            // Cash payment
            $printer->text("Method: Cash\n");
            $paidText = sprintf("Paid: %12s", "N$ " . number_format($orderData['cash_received'], 2));
            $printer->text($paidText . "\n");
            $change = $orderData['cash_received'] - $subtotal;
            $changeText = sprintf("Change: %10s", "N$ " . number_format($change, 2));
            $printer->text($changeText . "\n");
        }
        
        $printer->feed();
        $printer->text(str_repeat('-', 48) . "\n");
        $printer->feed();
        
        // Footer section
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        // Reduced from feed(4) to feed(1) to save paper
        $printer->feed(1);
    }

    // Send final commands
    try {
        $connector->write(chr(27).chr(109));
        $printer->cut();
        
        // Open the cash drawer only for cash payments and mixed payments
        if (!isset($orderData['creditor_id']) && !isset($orderData['is_cashup_report']) && !isset($orderData['is_balance_receipt'])) {
            // Open drawer for cash payments, mixed payments (which include cash), but not for e-wallet only
            if (!isset($orderData['payment_method']) || ($orderData['payment_method'] !== 'e-wallet' && $orderData['payment_method'] !== 'credit')) {
                $printer->pulse();
            }
        }
        
        // Close the printer connection
        $printer->close();
    } catch (Exception $e) {
        throw new Exception("Failed to complete printing: " . $e->getMessage());
    }

    // Return success response with enriched orderData for Android interceptor
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Receipt printed successfully',
        'printer_used' => $printerName,
        'client_ip' => $clientIP,
        'connection_type' => $isNetworkPrinter ? 'network' : 'local',
        'order_data' => $orderData  // Include enriched orderData for Android
    ]);

} catch (Exception $e) {
    // Enrich orderData even on error for Android compatibility
    if (isset($orderData) && is_array($orderData)) {
        try {
            $db = new PDO('sqlite:info.db');
            $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($businessInfo) {
                $orderData['business_name'] = $businessInfo['name'] ?? 'POS SOLUTION';
                $orderData['location'] = $businessInfo['location'] ?? '';
                $orderData['phone'] = $businessInfo['phone'] ?? '';
                $orderData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
                $orderData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
                $orderData['vat_rate'] = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
            }
        } catch (Exception $dbError) {
            // Ignore database errors in error handler
        }
    }
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'printer_attempted' => $printerName ?? 'unknown',
        'client_ip' => $clientIP ?? 'unknown',
        'connection_type' => ($isNetworkPrinter ?? false) ? 'network' : 'local',
        'details' => 'Please check if the printer is connected and powered on.',
        'order_data' => $orderData ?? []  // Include orderData even on error for Android
    ]);
}
exit;
?>
