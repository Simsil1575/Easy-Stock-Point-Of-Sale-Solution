<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Set timezone
date_default_timezone_set('Africa/Harare');

// Include the receipt printing libraries
require __DIR__ . '/vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

// Get POST parameters
$transactionId = $_POST['transaction_id'] ?? '';
$saleType = $_POST['sale_type'] ?? '';
$paymentStatus = $_POST['payment_status'] ?? '';

if (empty($transactionId) || empty($saleType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing transaction ID or sale type']);
    exit();
}

try {
    // Connect to databases
    $dbPos = new PDO('sqlite:pos.db');
    $dbInfo = new PDO('sqlite:info.db');
    
    // Get business info
    $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!'
        ];
    }
    
    $receiptData = null;
    
    if ($saleType === 'credit' || strpos($saleType, 'Credit') !== false) {
        // Handle credit sales
        $creditQuery = $dbPos->prepare("
            SELECT 
                cs.id as sale_id,
                cs.creditor_id,
                cs.total_amount,
                cs.paid_amount,
                cs.payment_status,
                cs.due_date,
                cs.created_at,
                cs.cashier_id,
                cr.name as creditor_name,
                GROUP_CONCAT(csi.product_name || ' x' || csi.quantity || ' @ N$' || csi.price, ', ') as products
            FROM credit_sales cs
            LEFT JOIN creditors cr ON cs.creditor_id = cr.id
            LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
            WHERE cs.id = :transaction_id
            GROUP BY cs.id
        ");
        $creditQuery->bindParam(':transaction_id', $transactionId);
        $creditQuery->execute();
        $creditData = $creditQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$creditData) {
            throw new Exception('Credit transaction not found');
        }
        
        // Get items for the credit sale
        $itemsQuery = $dbPos->prepare("
            SELECT product_name as name, quantity, price
            FROM credit_sale_items 
            WHERE sale_id = :sale_id
        ");
        $itemsQuery->bindParam(':sale_id', $transactionId);
        $itemsQuery->execute();
        $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Format items for receipt
        // IMPORTANT: price should be TOTAL for the line item (matches receipt.php and home.php format)
        $formattedItems = [];
        foreach ($items as $item) {
            $quantity = intval($item['quantity']);
            // In credit_sale_items, price is stored as unit price, so calculate total
            $unitPrice = floatval($item['price']);
            $totalPrice = $unitPrice * $quantity; // Calculate total price for line item
            
            // Only add if we have valid data
            if (!empty($item['name']) && $totalPrice > 0) {
                $formattedItems[] = [
                    'name' => $item['name'],
                    'quantity' => $quantity,
                    'price' => $totalPrice  // Total price for this line item (matches receipt.php expectation)
                ];
            }
        }
        
        error_log("Credit sale - Formatted " . count($formattedItems) . " items from " . count($items) . " database items for sale_id: $transactionId");
        
        // Check if this was an EFT credit payment
        $eftPaymentQuery = $dbPos->prepare("
            SELECT ep.amount, ep.wallet_provider, ep.transaction_ref, ep.payment_date
            FROM eft_payments ep
            WHERE ep.order_id = :transaction_id
        ");
        $eftPaymentQuery->bindParam(':transaction_id', $transactionId);
        $eftPaymentQuery->execute();
        $eftPayment = $eftPaymentQuery->fetch(PDO::FETCH_ASSOC);
        
        $receiptData = [
            'sale_id' => $creditData['sale_id'],
            'creditor_id' => $creditData['creditor_id'],
            'creditor_name' => $creditData['creditor_name'],
            'total_amount' => $creditData['total_amount'],
            'due_date' => $creditData['due_date'],
            'items' => $formattedItems,
            'cashier_username' => $_SESSION['username'],
            'created_at' => $creditData['created_at']
        ];
        
        // Add payment method specific data
        if ($eftPayment && ($paymentStatus === 'eft' || $saleType === 'Credit (EFT)')) {
            $receiptData['payment_method'] = 'e-wallet';
            $receiptData['wallet_provider'] = $eftPayment['wallet_provider'];
            $receiptData['transaction_ref'] = $eftPayment['transaction_ref'];
            $receiptData['payment_amount'] = $eftPayment['amount'];
        } else if ($paymentStatus === 'paid' || $paymentStatus === 'partial') {
            $receiptData['payment_type'] = 'cash';
            $receiptData['cash_received'] = $creditData['paid_amount'];
        }
        
    } else if ($saleType === 'eft' || $saleType === 'EFT') {
        // Handle EFT sales
        $eftQuery = $dbPos->prepare("
            SELECT 
                o.id as order_id,
                o.total,
                o.created_at,
                o.cashier_id,
                ep.amount as eft_amount,
                ep.wallet_provider,
                ep.transaction_ref,
                GROUP_CONCAT(oi.product_name || ' x' || oi.quantity || ' @ N$' || oi.price, ', ') as products
            FROM orders o
            JOIN eft_payments ep ON o.id = ep.order_id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.id = :transaction_id
            GROUP BY o.id
        ");
        $eftQuery->bindParam(':transaction_id', $transactionId);
        $eftQuery->execute();
        $eftData = $eftQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$eftData) {
            throw new Exception('EFT transaction not found');
        }
        
        // Get items for the order
        $itemsQuery = $dbPos->prepare("
            SELECT product_name as name, quantity, price
            FROM order_items 
            WHERE order_id = :order_id
        ");
        $itemsQuery->bindParam(':order_id', $transactionId);
        $itemsQuery->execute();
        $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Format items for receipt
        // IMPORTANT: price should be TOTAL for the line item (matches receipt.php and home.php format)
        $formattedItems = [];
        foreach ($items as $item) {
            $quantity = intval($item['quantity']);
            // In order_items, price is stored as unit price, so calculate total
            $unitPrice = floatval($item['price']);
            $totalPrice = $unitPrice * $quantity; // Calculate total price for line item
            
            // Only add if we have valid data
            if (!empty($item['name']) && $totalPrice > 0) {
                $formattedItems[] = [
                    'name' => $item['name'],
                    'quantity' => $quantity,
                    'price' => $totalPrice  // Total price for this line item (matches receipt.php expectation)
                ];
            }
        }
        
        error_log("EFT order - Formatted " . count($formattedItems) . " items from " . count($items) . " database items for order_id: $transactionId");
        
        $receiptData = [
            'order_id' => $eftData['order_id'],
            'payment_method' => 'e-wallet',
            'wallet_provider' => $eftData['wallet_provider'],
            'transaction_ref' => $eftData['transaction_ref'],
            'items' => $formattedItems,
            'cashier_username' => $_SESSION['username'],
            'created_at' => $eftData['created_at']
        ];
        
    } else {
        // Handle cash sales
        $cashQuery = $dbPos->prepare("
            SELECT 
                o.id as order_id,
                o.total,
                o.created_at,
                o.cashier_id,
                GROUP_CONCAT(oi.product_name || ' x' || oi.quantity || ' @ N$' || oi.price, ', ') as products
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN eft_payments ep ON o.id = ep.order_id
            WHERE o.id = :transaction_id AND ep.order_id IS NULL
            GROUP BY o.id
        ");
        $cashQuery->bindParam(':transaction_id', $transactionId);
        $cashQuery->execute();
        $cashData = $cashQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$cashData) {
            throw new Exception('Cash transaction not found');
        }
        
        // Get items for the order
        $itemsQuery = $dbPos->prepare("
            SELECT product_name as name, quantity, price
            FROM order_items 
            WHERE order_id = :order_id
        ");
        $itemsQuery->bindParam(':order_id', $transactionId);
        $itemsQuery->execute();
        $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Format items for receipt
        // IMPORTANT: price should be TOTAL for the line item (matches receipt.php and home.php format)
        $formattedItems = [];
        $total = 0;
        foreach ($items as $item) {
            $quantity = intval($item['quantity']);
            // In order_items, price is stored as unit price, so calculate total
            $unitPrice = floatval($item['price']);
            $lineTotal = $unitPrice * $quantity; // Calculate total price for line item
            
            // Only add if we have valid data
            if (!empty($item['name']) && $lineTotal > 0) {
                $formattedItems[] = [
                    'name' => $item['name'],
                    'quantity' => $quantity,
                    'price' => $lineTotal // Total price for this line item (matches receipt.php expectation)
                ];
                $total += $lineTotal;
            }
        }
        
        // Log for debugging
        error_log("Cash order - Formatted " . count($formattedItems) . " items from " . count($items) . " database items for order_id: $transactionId");
        
        $receiptData = [
            'order_id' => $cashData['order_id'],
            'cash_received' => $cashData['total'] ?? $total, // Use actual total from order
            'items' => $formattedItems,
            'cashier_username' => $_SESSION['username'],
            'created_at' => $cashData['created_at'],
            'payment_method' => 'cash'
        ];
    }
    
    if (!$receiptData) {
        throw new Exception('Transaction data could not be retrieved');
    }
    
    // Get business info
    $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'footer_text' => 'Thank you for your purchase!',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    
    // Add business info to receipt data
    $receiptData['business_name'] = $businessInfo['name'];
    $receiptData['location'] = $businessInfo['location'] ?? '';
    $receiptData['phone'] = $businessInfo['phone'] ?? '';
    $receiptData['footer_text'] = $businessInfo['footer_text'] ?? 'Thank you for your purchase!';
    $receiptData['vat_inclusive'] = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $receiptData['vat_rate'] = floatval($businessInfo['vat_rate'] ?? 15.0);
    
    // Calculate total from items if not already set
    if (!isset($receiptData['total']) && isset($receiptData['items'])) {
        $calculatedTotal = 0;
        foreach ($receiptData['items'] as $item) {
            $calculatedTotal += floatval($item['price']);
        }
        $receiptData['total'] = $calculatedTotal;
    }
    
    // Check if this is an Android request (via User-Agent or explicit parameter)
    $isAndroid = isset($_POST['android_print']) || 
                 (isset($_SERVER['HTTP_USER_AGENT']) && 
                  (stripos($_SERVER['HTTP_USER_AGENT'], 'android') !== false || 
                   stripos($_SERVER['HTTP_USER_AGENT'], 'median') !== false));
    
    // If Android, return JSON data for native printing
    if ($isAndroid) {
        header('Content-Type: application/json');
        
        // Ensure items is a proper array (not empty)
        if (!isset($receiptData['items']) || empty($receiptData['items'])) {
            // Try to get items from database if missing
            if (isset($receiptData['order_id'])) {
                $itemsQuery = $dbPos->prepare("SELECT product_name as name, quantity, price FROM order_items WHERE order_id = ?");
                $itemsQuery->execute([$receiptData['order_id']]);
                $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
                $formattedItems = [];
                foreach ($items as $item) {
                    $quantity = intval($item['quantity']);
                    $unitPrice = floatval($item['price']);
                    $totalPrice = $unitPrice * $quantity; // Calculate total for line item
                    
                    if (!empty($item['name']) && $totalPrice > 0) {
                        $formattedItems[] = [
                            'name' => $item['name'],
                            'quantity' => $quantity,
                            'price' => $totalPrice  // Total price for line item
                        ];
                    }
                }
                $receiptData['items'] = $formattedItems;
                error_log("Android reprint - Fetched " . count($formattedItems) . " items from order_items for order_id: " . $receiptData['order_id']);
            } elseif (isset($receiptData['sale_id'])) {
                $itemsQuery = $dbPos->prepare("SELECT product_name as name, quantity, price FROM credit_sale_items WHERE sale_id = ?");
                $itemsQuery->execute([$receiptData['sale_id']]);
                $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
                $formattedItems = [];
                foreach ($items as $item) {
                    $quantity = intval($item['quantity']);
                    $unitPrice = floatval($item['price']);
                    $totalPrice = $unitPrice * $quantity; // Calculate total for line item
                    
                    if (!empty($item['name']) && $totalPrice > 0) {
                        $formattedItems[] = [
                            'name' => $item['name'],
                            'quantity' => $quantity,
                            'price' => $totalPrice  // Total price for line item
                        ];
                    }
                }
                $receiptData['items'] = $formattedItems;
                error_log("Android reprint - Fetched " . count($formattedItems) . " items from credit_sale_items for sale_id: " . $receiptData['sale_id']);
            }
        }
        
        // Ensure items is always an array (even if empty)
        if (!isset($receiptData['items'])) {
            $receiptData['items'] = [];
        }
        
        // Log for debugging
        error_log("Android reprint - Transaction ID: $transactionId, Sale Type: $saleType");
        error_log("Android reprint - Items count: " . count($receiptData['items']));
        if (!empty($receiptData['items'])) {
            error_log("Android reprint - First item: " . json_encode($receiptData['items'][0]));
            error_log("Android reprint - All items: " . json_encode($receiptData['items']));
        } else {
            error_log("WARNING: Android reprint - Items array is EMPTY!");
        }
        error_log("Android reprint - Receipt data keys: " . implode(', ', array_keys($receiptData)));
        
        // Verify items array structure before encoding
        if (isset($receiptData['items']) && is_array($receiptData['items'])) {
            foreach ($receiptData['items'] as $idx => $item) {
                if (!isset($item['name']) || !isset($item['quantity']) || !isset($item['price'])) {
                    error_log("ERROR: Item $idx is missing required fields: " . json_encode($item));
                }
            }
        }
        
        // Return receipt_data directly (not wrapped) for Android
        $response = [
            'success' => true,
            'message' => 'Receipt data ready for printing',
            'receipt_data' => $receiptData,
            'transaction_id' => $transactionId,
            'sale_type' => $saleType
        ];
        
        // Log the full response structure
        error_log("Android reprint - Full response structure: " . json_encode($response, JSON_PRETTY_PRINT));
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
    
    // Otherwise, print directly to physical printer (server-side printing)
    try {
        // Set timezone to Namibia
        date_default_timezone_set('Africa/Harare');
        
        // Detect client IP address to determine which printer to use
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
        
        // Determine printer connection based on client IP
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
        
        // Print the receipt using the same logic as receipt.php
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($businessInfo['name'] . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(true);
        $printer->text($businessInfo['location'] . "\n");
        $printer->setEmphasis(false);
        $printer->text("Tel: " . $businessInfo['phone'] . "\n");
        $printer->text("Cashier: " . $_SESSION['username'] . "\n");
        $printer->feed();
        
        // Print receipt content based on transaction type
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->setEmphasis(true);
        $receiptNumber = isset($receiptData['order_id']) ? $receiptData['order_id'] : (isset($receiptData['sale_id']) ? $receiptData['sale_id'] : uniqid());
        $receiptType = isset($receiptData['sale_id']) ? "Credit Sale" : "Receipt";
        $printer->text($receiptType . " #: " . $receiptNumber . "\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->text("Date: " . date('Y-m-d H:i') . "\n");
        $printer->feed();
        
        // Items section header
        $printer->setEmphasis(true);
        $printer->text(sprintf("%-20s %3s %9s\n", "Item", "Qty", "Amount"));
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 42) . "\n");
        
        $subtotal = 0;
        if (isset($receiptData['items']) && is_array($receiptData['items']) && !empty($receiptData['items'])) {
            foreach ($receiptData['items'] as $item) {
                $name = $item['name'] ?? 'Item';
                $quantity = intval($item['quantity'] ?? 1);
                // price should be total for the line item (matches receipt.php format)
                $amount = floatval($item['price'] ?? 0);
                $price = $quantity > 0 ? $amount / $quantity : $amount; // Calculate unit price
                $subtotal += $amount;
                
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
        } else {
            error_log("WARNING: No items found in receiptData for reprint!");
            $printer->text("(No items)\n");
        }
        
        // Totals section
        $printer->feed();
        $printer->setEmphasis(true);
        $totalText = sprintf("TOTAL: N$ %8.2f", $subtotal);
        $spaces = 42 - strlen($totalText);
        $printer->text(str_repeat(' ', $spaces) . $totalText . "\n");
        $printer->setEmphasis(false);
        $printer->feed();
        
        // Payment information section
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->setEmphasis(true);
        $printer->text("PAYMENT INFORMATION\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', 42) . "\n");
        
        if (isset($receiptData['creditor_id'])) {
            // Credit payment
            $printer->text("Method: Credit\n");
            $printer->text(sprintf("%-10s %s\n", "ID:", $receiptData['creditor_id']));
            if (isset($receiptData['creditor_name'])) {
                $printer->text(sprintf("%-10s %s\n", "Name:", $receiptData['creditor_name']));
            }
            if (isset($receiptData['due_date'])) {
                $printer->text(sprintf("%-10s %s\n", "Due:", $receiptData['due_date']));
            }
            // Show partial payment info if not fully paid
            if (isset($receiptData['payment_type']) && $receiptData['payment_type'] === 'cash' && isset($receiptData['cash_received']) && isset($receiptData['total_amount'])) {
                if ($receiptData['cash_received'] < $receiptData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $receiptData['cash_received']));
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $receiptData['total_amount'] - $receiptData['cash_received']));
                }
            }
            if (isset($receiptData['payment_method']) && $receiptData['payment_method'] === 'e-wallet' && isset($receiptData['payment_amount']) && isset($receiptData['total_amount'])) {
                if ($receiptData['payment_amount'] < $receiptData['total_amount']) {
                    $printer->setEmphasis(true);
                    $printer->text("Partial Payment (EFT)\n");
                    $printer->setEmphasis(false);
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $receiptData['payment_amount']));
                    $printer->text(sprintf("%-10s N$%8.2f\n", "Balance:", $receiptData['total_amount'] - $receiptData['payment_amount']));
                }
            }
            $printer->feed();
            // Add barcode for transaction ID ONLY for credit sales
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Transaction ID:\n");
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT);
            $printer->barcode($receiptNumber, Printer::BARCODE_CODE39);
            $printer->selectPrintMode();
            $printer->feed();
        } else if (isset($receiptData['payment_method']) && $receiptData['payment_method'] === 'e-wallet') {
            // E-wallet payment
            $printer->text("Method: EFT\n");
            $printer->text(sprintf("%-10s %s\n", "Provider:", $receiptData['wallet_provider']));
            $ref = $receiptData['transaction_ref'];
            if (strlen($ref) > 30) {
                $ref = substr($ref, 0, 27) . '...';
            }
            $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
            $printer->text(sprintf("%-10s N$%8.2f\n", "Paid:", $subtotal));
        } else if (isset($receiptData['payment_method']) && $receiptData['payment_method'] === 'mixed') {
            // Mixed payment (Cash + EFT)
            $printer->text("Method: Mixed Payment\n");
            $printer->text(str_repeat('-', 42) . "\n");
            
            // Cash portion
            if (isset($receiptData['cash_amount']) && $receiptData['cash_amount'] > 0) {
                $printer->text(sprintf("%-10s N$%8.2f\n", "Cash:", $receiptData['cash_amount']));
            }
            
            // EFT portion
            if (isset($receiptData['eft_amount']) && $receiptData['eft_amount'] > 0) {
                $printer->text(sprintf("%-10s N$%8.2f\n", "EFT:", $receiptData['eft_amount']));
                if (isset($receiptData['wallet_provider'])) {
                    $printer->text(sprintf("%-10s %s\n", "Provider:", $receiptData['wallet_provider']));
                }
                if (isset($receiptData['transaction_ref']) && !empty($receiptData['transaction_ref'])) {
                    $ref = $receiptData['transaction_ref'];
                    if (strlen($ref) > 36) {
                        $ref = substr($ref, 0, 33) . '...';
                    }
                    $printer->text(sprintf("%-10s %s\n", "Ref:", $ref));
                }
            }
            
            $printer->text(str_repeat('-', 42) . "\n");
            $printer->text(sprintf("%-10s N$%8.2f\n", "Total:", $subtotal));
            
            // Calculate and show change if cash amount is greater than total
            if (isset($receiptData['cash_amount']) && $receiptData['cash_amount'] > $subtotal) {
                $change = $receiptData['cash_amount'] - $subtotal;
                $changeText = sprintf("Change: %10s", "N$ " . number_format($change, 2));
                $printer->text($changeText . "\n");
            }
        } else {
            // Cash payment
            $printer->text("Method: Cash\n");
            $paidText = sprintf("Paid: %12s", "N$ " . number_format($receiptData['cash_received'], 2));
            $printer->text($paidText . "\n");
            $change = $receiptData['cash_received'] - $subtotal;
            $changeText = sprintf("Change: %10s", "N$ " . number_format($change, 2));
            $printer->text($changeText . "\n");
        }
        
        $printer->feed();
        $printer->text(str_repeat('-', 42) . "\n");
        $printer->feed();
        
        // Footer section
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($businessInfo['footer_text'] . "\n");
        $printer->feed(4);
        
        // Send final commands
        $connector->write(chr(27).chr(109));
        $printer->cut();
        
        // NOTE: Cash drawer intentionally NOT opened for receipt reprints
        // To prevent unwanted drawer opens during reprint operations
        
        // Close the printer connection
        $printer->close();
        
    } catch (Exception $e) {
        throw new Exception('Receipt printing failed: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Receipt reprinted successfully',
        'transaction_id' => $transactionId,
        'sale_type' => $saleType
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'transaction_id' => $transactionId,
        'sale_type' => $saleType
    ]);
}
?>
