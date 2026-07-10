<?php
/**
 * Lay-bye statement / balance receipt for Reports and view-laybye flows.
 * Returns receipt_data for Android/QZ or prints via ESC/POS (same paths as reprint_receipt.php).
 */
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

date_default_timezone_set('Africa/Harare');

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/ensure_laybye_schema.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

header('Content-Type: application/json');

$laybyeId = (int) ($_POST['laybye_id'] ?? 0);
if ($laybyeId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing laybye_id']);
    exit;
}

try {
    $dbPos = new PDO('sqlite:pos.db');
    $dbPos->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbInfo = new PDO('sqlite:info.db');
    ensureLaybyeSchema($dbPos);

    $use_qz_tray = 0;
    try {
        $dbPos->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
    }
    try {
        $settingRow = $dbPos->query("SELECT use_qz_tray FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $use_qz_tray = (int) ($settingRow['use_qz_tray'] ?? 0);
    } catch (PDOException $e) {
        $use_qz_tray = 0;
    }

    $accStmt = $dbPos->prepare("
        SELECT l.*, c.name AS creditor_name
        FROM laybye_accounts l
        LEFT JOIN creditors c ON c.id = l.creditor_id
        WHERE l.id = ?
    ");
    $accStmt->execute([$laybyeId]);
    $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        throw new Exception('Lay-bye not found');
    }

    $itemsStmt = $dbPos->prepare('SELECT product_name, quantity, price FROM laybye_items WHERE laybye_id = ? ORDER BY added_at ASC');
    $itemsStmt->execute([$laybyeId]);
    $layRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $goodsTotal = 0;
    foreach ($layRows as $r) {
        $qty = (int) $r['quantity'];
        $unit = floatval($r['price']);
        $line = round($qty * $unit, 2);
        $goodsTotal += $line;
        $items[] = [
            'name' => $r['product_name'],
            'quantity' => $qty,
            'price' => $line,
            'unit_price' => $unit,
        ];
    }

    $payStmt = $dbPos->prepare("SELECT payment_date, payment_kind, amount, payment_method FROM laybye_payments WHERE laybye_id = ? ORDER BY payment_date DESC LIMIT 20");
    $payStmt->execute([$laybyeId]);
    $payRows = $payStmt->fetchAll(PDO::FETCH_ASSOC);
    $paymentLines = [];
    foreach ($payRows as $p) {
        $paymentLines[] = sprintf(
            '%s %s N$%s %s',
            $p['payment_date'] ?? '',
            $p['payment_kind'] ?? '',
            number_format((float) ($p['amount'] ?? 0), 2),
            $p['payment_method'] ?? ''
        );
    }

    $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => '',
            'phone' => '',
            'footer_text' => 'Thank you!',
        ];
    }

    $orderData = [
        'is_balance_receipt' => true,
        'is_laybye_balance_receipt' => true,
        'print_only' => true,
        'laybye_reference' => $acc['reference'] ?? '',
        'creditor_name' => $acc['creditor_name'] ?? 'N/A',
        'laybye_status' => $acc['status'] ?? '',
        'laybye_plan_frequency' => $acc['plan_frequency'] ?? '',
        'laybye_next_due_date' => $acc['next_due_date'] ?? '',
        'laybye_goods_total' => round($goodsTotal, 2),
        'laybye_deposit_amount' => round(floatval($acc['deposit_amount'] ?? 0), 2),
        'total_balance' => round(floatval($acc['balance_due'] ?? 0), 2),
        'items' => $items,
        'laybye_payment_lines' => $paymentLines,
        'business_name' => $businessInfo['name'] ?? 'POS SOLUTION',
        'location' => $businessInfo['location'] ?? '',
        'phone' => $businessInfo['phone'] ?? '',
        'footer_text' => $businessInfo['footer_text'] ?? 'Thank you!',
        'cashier_username' => $_SESSION['username'] ?? '',
    ];

    $isAndroid = isset($_POST['android_print'])
        || (isset($_SERVER['HTTP_USER_AGENT'])
            && (stripos($_SERVER['HTTP_USER_AGENT'], 'android') !== false
                || stripos($_SERVER['HTTP_USER_AGENT'], 'median') !== false));

    if ($isAndroid || $use_qz_tray) {
        echo json_encode([
            'success' => true,
            'message' => 'Lay-bye statement ready for printing',
            'receipt_data' => $orderData,
            'order_data' => $orderData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Server-side thermal print (aligned with reprint_receipt.php)
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
    $printerName = 'XP-58SERIES';
    $isNetworkPrinter = false;
    if ($clientIP === '192.168.178.87') {
        $printerName = 'POSPrinter POS-80C';
        $isNetworkPrinter = true;
    }
    $receiptWidth = ($printerName === 'POSPrinter POS-80C' || $isNetworkPrinter) ? 42 : 32;
    $receiptTruncate = $receiptWidth - 3;

    if ($isNetworkPrinter) {
        $connector = new NetworkPrintConnector('192.168.1.7', 9100);
    } else {
        $connector = new WindowsPrintConnector($printerName);
    }
    $printer = new Printer($connector);

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
    $printer->text(($businessInfo['name'] ?? 'POS') . "\n");
    $printer->selectPrintMode();
    $printer->text("LAY-BYE STATEMENT\n");
    $printer->feed();
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->text(str_repeat('-', $receiptWidth) . "\n");
    $printer->text(sprintf("%-12s %s\n", "Reference:", $orderData['laybye_reference']));
    $printer->text(sprintf("%-12s %s\n", "Customer:", $orderData['creditor_name']));
    $printer->text(sprintf("%-12s %s\n", "Status:", $orderData['laybye_status']));
    $printer->text(sprintf("%-12s %s\n", "Plan:", $orderData['laybye_plan_frequency']));
    if (!empty($orderData['laybye_next_due_date'])) {
        $printer->text(sprintf("%-12s %s\n", "Next due:", $orderData['laybye_next_due_date']));
    }
    $printer->text(sprintf("%-12s N$%8.2f\n", "Goods total:", $orderData['laybye_goods_total']));
    $printer->text(sprintf("%-12s N$%8.2f\n", "Deposit:", $orderData['laybye_deposit_amount']));
    $printer->text(str_repeat('-', $receiptWidth) . "\n");

    if (!empty($orderData['items'])) {
        $printer->setEmphasis(true);
        $printer->text("GOODS ON LAY-BYE\n");
        $printer->setEmphasis(false);
        foreach ($orderData['items'] as $item) {
            $itemName = $item['name'];
            if (strlen($itemName) > ($receiptWidth - 4)) {
                $itemName = substr($itemName, 0, ($receiptWidth - 7)) . '...';
            }
            $qty = (int) $item['quantity'];
            $unitPrice = floatval($item['unit_price'] ?? 0);
            $itemTotal = floatval($item['price']);
            $printer->text($itemName . "\n");
            $printer->text(sprintf("  %dx N$%.2f = N$%.2f\n", $qty, $unitPrice, $itemTotal));
        }
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
    }

    if (!empty($orderData['laybye_payment_lines'])) {
        $printer->setEmphasis(true);
        $printer->text("PAYMENT HISTORY\n");
        $printer->setEmphasis(false);
        foreach ($orderData['laybye_payment_lines'] as $line) {
            $ln = strlen($line) > $receiptWidth ? substr($line, 0, $receiptTruncate) . '...' : $line;
            $printer->text($ln . "\n");
        }
        $printer->text(str_repeat('-', $receiptWidth) . "\n");
    }

    $printer->setEmphasis(true);
    $printer->text(sprintf("%-20s N$%8.2f\n", "BALANCE DUE:", $orderData['total_balance']));
    $printer->setEmphasis(false);
    $printer->feed();
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text(($businessInfo['footer_text'] ?? '') . "\n");
    $printer->feed(3);
    $connector->write(chr(27) . chr(109));
    $printer->cut();
    $printer->initialize();
    $printer->close();

    echo json_encode([
        'success' => true,
        'message' => 'Lay-bye statement printed',
        'order_data' => $orderData,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
