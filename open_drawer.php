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

function getUseQzTrayFlag(): int {
    try {
        $dbPos = new PDO('sqlite:pos.db');
        $dbPos->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $dbPos->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            // ignore
        }
        $row = $dbPos->query("SELECT use_qz_tray FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return (int)($row['use_qz_tray'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// Support GET (e.g. from Cash Management page) or POST with JSON body
$isGet = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET';
$orderData = null;
if (!$isGet) {
    $raw = file_get_contents('php://input');
    $orderData = $raw !== '' ? json_decode($raw, true) : null;
}

// Open cash drawer: GET request or POST with open_drawer_only (no order data required)
$openDrawerOnly = $isGet || (is_array($orderData) && !empty($orderData['open_drawer_only']));

if ($openDrawerOnly) {
    try {
        $use_qz_tray = getUseQzTrayFlag();

        // If QZ Tray enabled, route drawer open via qzreceipt.php (needs browser QZ Tray).
        if ($use_qz_tray) {
            $payload = [
                'open_drawer_only' => true,
                'cashier_username' => ($orderData['cashier_username'] ?? null)
            ];
            $encoded = urlencode(json_encode($payload));

            // GET calls can be redirected to qzreceipt.php to trigger drawer pulse.
            if ($isGet) {
                header('Location: qzreceipt.php?data=' . $encoded);
                exit;
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Drawer open requested via QZ Tray',
                'receipt_data' => $payload,
                'order_data' => $payload
            ]);
            exit;
        }

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

// POST with order data but not open_drawer_only: this script only handles drawer open
header('Content-Type: application/json');
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Use print endpoint for receipts. This endpoint only opens the cash drawer (GET or open_drawer_only).']);
