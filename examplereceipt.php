<?php
/* Call this file 'hello-world.php' */
require __DIR__ . '/vendor/autoload.php';
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\Printer;

// Get the order data from the POST request
$orderData = json_decode(file_get_contents('php://input'), true);

if (!$orderData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No order data received']);
    exit;
}

try {
    // Set timezone to Namibia
    date_default_timezone_set('Africa/Windhoek');

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
    
    // Create printer connection with port from database
    $printerPort = $businessInfo['printer_port'] ?? 'COM4';
    $connector = new FilePrintConnector($printerPort);
    $printer = new Printer($connector);

    // Store header with emphasis
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
    $printer->text($businessInfo['name'] . "\n");
    $printer->selectPrintMode(); // Reset print mode
    $printer->setEmphasis(true);
    $printer->text($businessInfo['location'] . "\n");
    $printer->setEmphasis(false);
    $printer->text("Tel: " . $businessInfo['phone'] . "\n");
    $printer->feed();

    // Check if this is a cash-up report or regular receipt
    if (isset($orderData['is_cashup_report']) && $orderData['is_cashup_report']) {
        // CASH-UP REPORT PRINTING
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("--------------------------------\n");
        $printer->setEmphasis(true);
        $printer->text("DAILY CASH-UP REPORT\n");
        $printer->setEmphasis(false);
        $printer->text("--------------------------------\n");
        $printer->text("Date: " . $orderData['date'] . "\n");
        $printer->text("Printed on: " . date('Y-m-d H:i:s') . "\n");
        
        // Add cashier username to the report
        $printer->text("Prepared by: " . $orderData['cashier_username'] . "\n");
        $printer->feed();

        $printer->text("================================\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        
        // Format and print cash-up summary
        $cashSales = $orderData['total_cash_sales'];
        $creditSales = $orderData['credit_sales_total'];
        $unpaidCredit = $orderData['unpaid_credit'];
        $cashOnHand = $orderData['cash_on_hand'];
        
        $printer->text(sprintf("%-20s N$%9.2f\n", "CASH SALES:", $cashSales));
        $printer->text(sprintf("%-20s N$%9.2f\n", "CREDIT SALES:", $creditSales));
        $printer->text(sprintf("%-20s N$%9.2f\n", "UNPAID CREDIT:", $unpaidCredit));
        $printer->text("--------------------------------\n");
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s N$%9.2f\n", "CASH ON HAND:", $cashOnHand));
        $printer->setEmphasis(false);
        $printer->text("================================\n");
        $printer->feed();

        // Add signature line
        $printer->feed();
        $printer->text("--------------------------------\n");
        $printer->text("Signature: _____________________\n");
        $printer->feed();
        
        // Footer
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("*** End of Cash-up Report ***\n");
        $printer->feed();
    } else {
        // REGULAR RECEIPT PRINTING (existing code)
        // Receipt details with better formatting
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("--------------------------------\n");
        $printer->setEmphasis(true);
        $printer->text("RECEIPT\n");
        $printer->setEmphasis(false);
        $printer->text("--------------------------------\n");
        $printer->text("Date: " . date('Y-m-d H:i:s') . "\n");
        
        // Use order_id or sale_id from the data instead of uniqid()
        if (isset($orderData['order_id'])) {
            $receiptNumber = $orderData['order_id'];
            $receiptType = "Receipt";
        } elseif (isset($orderData['sale_id'])) {
            $receiptNumber = $orderData['sale_id'];
            $receiptType = "Credit Sale";
        } else {
            $receiptNumber = uniqid();
            $receiptType = "Receipt";
        }
        
        $printer->text($receiptType . " #: " . $receiptNumber . "\n");
        $printer->feed();

        // Items with improved layout
        $printer->setEmphasis(true);
        $printer->text("ITEMS PURCHASED\n");
        $printer->setEmphasis(false);
        $printer->text("--------------------------------\n");

        // Column headers with better alignment - using full width (42 chars typical for thermal receipt)
        $printer->text(sprintf("%-20s %7s %13s\n", "Item", "Qty×Price", "Amount"));
        $printer->text("--------------------------------\n");

        $subtotal = 0;
        foreach ($orderData['items'] as $item) {
            $name = $item['name'];
            $quantity = $item['quantity'];
            $price = $item['price'] / $quantity; // Calculate unit price
            $amount = $item['price'];
            $subtotal += $amount;
            
            // For long names, print full name on first line
            $printer->text($name . "\n");
            
            // Price and quantity on second line with perfect alignment
            // Format with spaces to reach from left to right edge
            $qtyPriceText = sprintf("%d × N$%.2f", $quantity, $price);
            $amountText = sprintf("N$%.2f", $amount);
            $printer->text(sprintf("%-20s %21s\n", $qtyPriceText, $amountText));
            
            // Add separator between items for clarity
            $printer->text("--------------------------------\n");
        }

        // Totals section with clear separation
        $printer->text("================================\n");
        $printer->setJustification(Printer::JUSTIFY_RIGHT);
        $printer->setEmphasis(true);
        $printer->text(sprintf("SUBTOTAL: N$%8.2f\n", $subtotal));
        
        // Add tax information if relevant (optional)
        if (isset($orderData['tax']) && $orderData['tax'] > 0) {
            $tax = $orderData['tax'];
            $printer->text(sprintf("TAX: N$%8.2f\n", $tax));
            $printer->text(sprintf("GRAND TOTAL: N$%8.2f\n", $subtotal + $tax));
        } else {
            $printer->text(sprintf("TOTAL: N$%8.2f\n", $subtotal));
        }
        
        $printer->setEmphasis(false);
        $printer->feed();

        // Payment information
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("--------------------------------\n");
        $printer->text("PAYMENT INFORMATION\n");
        $printer->text("--------------------------------\n");
        
        // Check if it's a credit sale
        if (isset($orderData['creditor_id'])) {
            $printer->text("Payment Method: Credit\n");
            $printer->text("Creditor ID: " . $orderData['creditor_id'] . "\n");
            if (isset($orderData['due_date'])) {
                $printer->text("Due Date: " . $orderData['due_date'] . "\n");
            }
        } else {
            $printer->text("Payment Method: Cash\n");
            $printer->text(sprintf("Amount Paid: N$%8.2f\n", $orderData['cash_received']));
            $change = $orderData['cash_received'] - $subtotal;
            $printer->text(sprintf("Change Due: N$%8.2f\n", $change));
        }
        $printer->feed();

        // Footer
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed();
    }

    // Cut the receipt
    $printer->cut();
    
    // Open the cash drawer only for cash payments
    if (!isset($orderData['creditor_id']) && !isset($orderData['is_cashup_report'])) {
        $printer->pulse();  // This sends the pulse signal to open the connected cash drawer
    }
    
    // Close the printer connection
    $printer->close();

    // Return success response
    echo json_encode(['success' => true, 'message' => 'Receipt printed successfully']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Printer error: ' . $e->getMessage()]);
}