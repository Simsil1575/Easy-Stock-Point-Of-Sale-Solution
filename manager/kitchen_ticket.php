<?php
// Simple Kitchen Order Ticket (KOT) printer using existing escpos setup.
// This prints a prep ticket for the kitchen/bar without opening the cash drawer.

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
if (ob_get_level()) {
    ob_clean();
}

require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

$orderData = json_decode(file_get_contents('php://input'), true);

if (!$orderData || empty($orderData['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No kitchen ticket data received']);
    exit;
}

try {
    date_default_timezone_set('Africa/Harare');

    // Load business info for header (reuse from info.db like main receipt)
    $db = new PDO('sqlite:info.db');
    $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Kitchen',
            'phone' => '',
        ];
    }

    // Determine printer based on client IP (same logic as receipt.php)
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
    $printerName = '';
    $isNetworkPrinter = false;

    if ($clientIP === '127.0.0.1' || $clientIP === '::1' || $clientIP === 'localhost' || $clientIP === $_SERVER['SERVER_ADDR']) {
        $printerName = "XP-58SERIES";
        $isNetworkPrinter = false;
    } elseif ($clientIP === '192.168.178.87') {
        $printerName = "POSPrinter POS-80C";
        $isNetworkPrinter = true;
    } else {
        $printerName = "XP-58SERIES";
        $isNetworkPrinter = false;
    }

    if ($isNetworkPrinter) {
        $connector = new NetworkPrintConnector("192.168.1.7", 9100);
    } else {
        $connector = new WindowsPrintConnector($printerName);
    }

    $printer = new Printer($connector);

    // Header
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
    $printer->text(($businessInfo['name'] ?? 'KITCHEN') . "\n");
    $printer->selectPrintMode();
    $printer->setEmphasis(true);
    $printer->text("KITCHEN ORDER TICKET\n");
    $printer->setEmphasis(false);
    // Removed feed() - meta info comes next

    // Meta info: table or takeaway, order id, cashier
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $labelType = isset($orderData['order_type']) && strtolower($orderData['order_type']) === 'takeaway'
        ? 'Takeaway'
        : 'Dine-in';

    if (!empty($orderData['table_name'])) {
        $printer->text("Table : " . $orderData['table_name'] . "\n");
    } elseif (!empty($orderData['tab_name'])) {
        $printer->text("Tab   : " . $orderData['tab_name'] . "\n");
    } else {
        $printer->text("Type  : " . $labelType . "\n");
    }

    if (!empty($orderData['order_id'])) {
        $printer->text("Order#: " . $orderData['order_id'] . "\n");
    }

    $printer->text("Time : " . date('Y-m-d H:i') . "\n");
    if (!empty($orderData['cashier_username'])) {
        $printer->text("By   : " . $orderData['cashier_username'] . "\n");
    }
    $printer->text(str_repeat('-', 32) . "\n");

    // Items (only names and quantities for speed)
    $printer->setEmphasis(true);
    $printer->text("ITEMS\n");
    $printer->setEmphasis(false);
    $printer->text(str_repeat('-', 32) . "\n");

    foreach ($orderData['items'] as $item) {
        $name = isset($item['name']) ? $item['name'] : '';
        $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;

        if ($qty <= 0) {
            $qty = 1;
        }

        if (strlen($name) > 24) {
            $name = substr($name, 0, 21) . '...';
        }

        // Print line like "x2  Burger Name"
        $printer->setEmphasis(true);
        $printer->text('x' . $qty . ' ');
        $printer->setEmphasis(false);
        $printer->text($name . "\n");
    }

    $printer->text(str_repeat('-', 32) . "\n");
    // Reduced from feed(2) to feed(1) to save paper
    $printer->feed(1);

    $printer->setJustification(Printer::JUSTIFY_CENTER);

    // Removed feed(2) - cut immediately to save paper

    $printer->cut();
    $printer->close();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Kitchen ticket printed',
        'printer_used' => $printerName,
        'client_ip' => $clientIP,
        'connection_type' => $isNetworkPrinter ? 'network' : 'local'
    ]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit;


