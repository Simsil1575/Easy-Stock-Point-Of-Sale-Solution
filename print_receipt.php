<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Set timezone
date_default_timezone_set('Africa/Harare');

// Include the receipt printing libraries
require __DIR__ . '/vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

// Get POST data (JSON)
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit();
}

try {
    // Connect to databases
    $dbInfo = new PDO('sqlite:info.db');
    $dbPos = new PDO('sqlite:pos.db');
    
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
    
    // Check if this is an Android request (via User-Agent or explicit parameter)
    $isAndroid = isset($input['android_print']) || 
                 (isset($_SERVER['HTTP_USER_AGENT']) && 
                  (stripos($_SERVER['HTTP_USER_AGENT'], 'android') !== false || 
                   stripos($_SERVER['HTTP_USER_AGENT'], 'median') !== false));

    // Check if QZ Tray is enabled (desktop/web)
    $use_qz_tray = 0;
    try {
        $dbPos->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $settingRow = $dbPos->query("SELECT use_qz_tray FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $use_qz_tray = (int)($settingRow['use_qz_tray'] ?? 0);
    } catch (PDOException $e) {
        $use_qz_tray = 0;
    }
    
    // If Android, return JSON data for native printing
    if ($isAndroid || $use_qz_tray) {
        header('Content-Type: application/json');
        
        // Ensure print_only flag is set for Android printing
        $input['print_only'] = true;
        $input['business_name'] = $businessInfo['name'];
        $input['location'] = $businessInfo['location'] ?? '';
        $input['phone'] = $businessInfo['phone'] ?? '';
        
        // Return receipt_data
        $response = [
            'success' => true,
            'message' => 'Receipt data ready for printing',
            'receipt_data' => $input,
            'order_data' => $input  // Also include as order_data for Android interceptor
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
    
    // Otherwise, print directly to physical printer (server-side printing)
    try {
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
        
        // Receipt width: 32 for 58mm (XP-58), 42 for 80mm (POS-80C / network) - same as receipt.php
        $receiptWidth = ($printerName === 'POSPrinter POS-80C' || $isNetworkPrinter) ? 42 : 32;
        
        // Create printer connection
        if ($isNetworkPrinter) {
            $connector = new NetworkPrintConnector("192.168.1.7", 9100);
        } else {
            $connector = new WindowsPrintConnector($printerName);
        }
        $printer = new Printer($connector);
        
        // Check if this is a Z-Report (Cash Up Report)
        if (isset($input['is_cashup_z_report']) && $input['is_cashup_z_report']) {
            printZReport($printer, $input, $businessInfo, $receiptWidth);
        } else {
            // Handle other receipt types here in the future
            throw new Exception('Unknown receipt type');
        }
        
        // Cut the paper
        $printer->cut();
        $printer->close();
        
        echo json_encode(['success' => true, 'message' => 'Receipt printed successfully']);
        
    } catch (Exception $e) {
        error_log('Printing error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Printer error: ' . $e->getMessage()]);
    }
    
} catch (Exception $e) {
    error_log('Receipt processing error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}

/**
 * Print Z-Report (Simplified Cash Up Report)
 * @param int $width Receipt width in characters (32 for 58mm, 42 for 80mm)
 */
function printZReport($printer, $data, $businessInfo, $width = 42) {
    // Header
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
    $printer->text($businessInfo['name'] . "\n");
    $printer->selectPrintMode();
    $printer->setEmphasis(true);
    $printer->text($businessInfo['location'] . "\n");
    $printer->setEmphasis(false);
    $printer->text("Tel: " . $businessInfo['phone'] . "\n");
    $printer->feed();
    
    // Z-Report Title
    $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
    $printer->text("===== Z-REPORT =====\n");
    $printer->selectPrintMode();
    $printer->feed();
    
    // Report Info
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->text(str_repeat('-', $width) . "\n");
    $printer->setEmphasis(true);
    if (!empty($data['date_range'])) {
        $printer->text("Period: " . $data['date_range'] . "\n");
    } else {
        $printer->text("Date: " . ($data['date'] ?? date('Y-m-d')) . "\n");
    }
    $printer->text("Cashier: " . ($data['cashier_name'] ?? $data['cashier_username'] ?? 'Unknown') . "\n");
    $printer->text("Generated: " . ($data['generated_at'] ?? date('Y-m-d H:i:s')) . "\n");
    $printer->setEmphasis(false);
    $printer->text(str_repeat('-', $width) . "\n");
    $printer->feed();
    
    // Sales Summary (column widths scale with receipt width)
    $labelW = (int)($width * 0.6);
    $amountW = $width - $labelW - 1;
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text("SALES SUMMARY\n");
    $printer->setEmphasis(false);
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->text(str_repeat('-', $width) . "\n");
    
    $cashSales = floatval($data['cash_sales'] ?? 0);
    $cardSales = floatval($data['card_sales'] ?? 0);
    $totalSales = floatval($data['total_sales'] ?? ($cashSales + $cardSales));
    
    $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Cash Sales:", "N$ " . number_format($cashSales, 2)));
    $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Card/EFT Sales:", "N$ " . number_format($cardSales, 2)));
    $printer->text(str_repeat('-', $width) . "\n");
    $printer->setEmphasis(true);
    $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "TOTAL SALES:", "N$ " . number_format($totalSales, 2)));
    $printer->setEmphasis(false);
    $printer->text(str_repeat('-', $width) . "\n");
    $printer->feed();
    
    // Cash in Till
    $printer->setEmphasis(true);
    $printer->text("CASH IN TILL\n");
    $printer->setEmphasis(false);
    $printer->text(str_repeat('-', $width) . "\n");
    $cashInTill = floatval($data['cash_in_till'] ?? 0);
    $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Expected Cash:", "N$ " . number_format($cashInTill, 2)));
    $printer->text(str_repeat('-', $width) . "\n");
    $printer->feed();
    
    // Credit & Tabs
    if (isset($data['unpaid_credit']) || isset($data['credit_returns']) || isset($data['open_tabs'])) {
        $printer->setEmphasis(true);
        $printer->text("CREDIT & TABS\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $width) . "\n");
        
        if (isset($data['unpaid_credit'])) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Unpaid Credit:", "N$ " . number_format(floatval($data['unpaid_credit']), 2)));
        }
        if (isset($data['credit_returns'])) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Credit Returns:", "N$ " . number_format(floatval($data['credit_returns']), 2)));
        }
        if (isset($data['open_tabs'])) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Open Tabs:", "N$ " . number_format(floatval($data['open_tabs']), 2)));
        }
        $printer->text(str_repeat('-', $width) . "\n");
        $printer->feed();
    }
    
    // Cash Transactions
    if (isset($data['cash_in']) || isset($data['cash_out']) || isset($data['expenses'])) {
        $printer->setEmphasis(true);
        $printer->text("CASH TRANSACTIONS\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $width) . "\n");
        
        if (isset($data['cash_in']) && floatval($data['cash_in']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Cash In:", "N$ " . number_format(floatval($data['cash_in']), 2)));
        }
        if (isset($data['cash_out']) && floatval($data['cash_out']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Cash Out:", "N$ " . number_format(floatval($data['cash_out']), 2)));
        }
        if (isset($data['expenses']) && floatval($data['expenses']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Expenses:", "N$ " . number_format(floatval($data['expenses']), 2)));
        }
        $printer->text(str_repeat('-', $width) . "\n");
        $printer->feed();
    }
    
    // Cash Back & Tips
    if (isset($data['cash_back_total']) || isset($data['tips'])) {
        $printer->setEmphasis(true);
        $printer->text("CASH BACK & TIPS\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $width) . "\n");
        
        if (isset($data['cash_back_total']) && floatval($data['cash_back_total']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Total Cash Back:", "N$ " . number_format(floatval($data['cash_back_total']), 2)));
            
            // Breakdown
            $subLabelW = max(18, $labelW - 2);
            $subAmountW = $width - $subLabelW - 2;
            if (isset($data['cash_back_beerhouse']) && floatval($data['cash_back_beerhouse']) > 0) {
                $printer->text(sprintf("  %-{$subLabelW}s %{$subAmountW}s\n", "Beerhouse:", "N$ " . number_format(floatval($data['cash_back_beerhouse']), 2)));
            }
            if (isset($data['cash_back_hubbly']) && floatval($data['cash_back_hubbly']) > 0) {
                $printer->text(sprintf("  %-{$subLabelW}s %{$subAmountW}s\n", "Hubbly:", "N$ " . number_format(floatval($data['cash_back_hubbly']), 2)));
            }
            if (isset($data['cash_back_customer']) && floatval($data['cash_back_customer']) > 0) {
                $printer->text(sprintf("  %-{$subLabelW}s %{$subAmountW}s\n", "Customer:", "N$ " . number_format(floatval($data['cash_back_customer']), 2)));
            }
        }
        
        if (isset($data['tips']) && floatval($data['tips']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Tips:", "N$ " . number_format(floatval($data['tips']), 2)));
        }
        
        $printer->text(str_repeat('-', $width) . "\n");
        $printer->feed();
    }
    
    // Hansa Payments
    if ((isset($data['hansa_cash']) && floatval($data['hansa_cash']) > 0) || 
        (isset($data['hansa_eft']) && floatval($data['hansa_eft']) > 0)) {
        $printer->setEmphasis(true);
        $printer->text("HANSA PAYMENTS\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $width) . "\n");
        
        if (isset($data['hansa_cash'])) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Hansa Cash:", "N$ " . number_format(floatval($data['hansa_cash']), 2)));
        }
        if (isset($data['hansa_eft'])) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Hansa EFT:", "N$ " . number_format(floatval($data['hansa_eft']), 2)));
        }
        
        $printer->text(str_repeat('-', $width) . "\n");
        $printer->feed();
    }
    
    // Other Items
    if ((isset($data['voids_count']) && intval($data['voids_count']) > 0) || 
        (isset($data['refunds_count']) && intval($data['refunds_count']) > 0) ||
        (isset($data['damages']) && floatval($data['damages']) > 0)) {
        $printer->setEmphasis(true);
        $printer->text("OTHER\n");
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $width) . "\n");
        
        if (isset($data['voids_count']) && intval($data['voids_count']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Voids:", intval($data['voids_count']) . " items"));
        }
        if (isset($data['refunds_count']) && intval($data['refunds_count']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Refunds:", intval($data['refunds_count']) . " items"));
        }
        if (isset($data['damages']) && floatval($data['damages']) > 0) {
            $printer->text(sprintf("%-{$labelW}s %{$amountW}s\n", "Damages:", "N$ " . number_format(floatval($data['damages']), 2)));
        }
        
        $printer->text(str_repeat('-', $width) . "\n");
        $printer->feed();
    }
    
    // Footer
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text(str_repeat('=', $width) . "\n");
    $printer->setEmphasis(true);
    $printer->text("END OF Z-REPORT\n");
    $printer->setEmphasis(false);
    $printer->text(str_repeat('=', $width) . "\n");
    $printer->feed(2);
}
