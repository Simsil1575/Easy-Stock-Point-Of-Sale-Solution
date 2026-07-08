<?php
require __DIR__ . '/../vendor/autoload.php';
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

try {
    // If QZ Tray enabled, return receipt_data so frontend uses sendToPrinter -> QZ.
    $use_qz_tray = 0;
    try {
        $dbPos = new PDO('sqlite:../pos.db');
        $dbPos->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try { $dbPos->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
        $row = $dbPos->query("SELECT use_qz_tray FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $use_qz_tray = (int)($row['use_qz_tray'] ?? 0);
    } catch (Exception $e) {
        $use_qz_tray = 0;
    }

    if ($use_qz_tray) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Drawer open requested via QZ Tray',
            'receipt_data' => ['open_drawer_only' => true],
            'order_data' => ['open_drawer_only' => true],
        ]);
        exit;
    }

    // Set timezone to Namibia
    date_default_timezone_set('Africa/Harare');

    // Connect to database and get business info
    $db = new PDO('sqlite:../info.db');
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
    $connector = new WindowsPrintConnector("XP-58SERIES");
    $printer = new Printer($connector);

    // Just open the cash drawer without printing anything
    $printer->pulse();
    $printer->close();
    
    echo json_encode(['success' => true, 'message' => 'Cash drawer opened successfully']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Printer error: ' . $e->getMessage()]);
} 