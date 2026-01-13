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

