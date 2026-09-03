<?php

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

/**
 * Load business_info row used for receipt/drawer printing.
 *
 * @return array<string, mixed>
 */
function receipt_load_business_info(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $defaults = [
        'name' => 'POS SOLUTION',
        'location' => 'Your Business Address',
        'phone' => 'Your Phone Number',
        'footer_text' => 'Thank you for your purchase!',
        'printer_port' => 'COM4',
        'vat_inclusive' => 'exclusive',
        'vat_rate' => 15.0,
    ];

    try {
        $db = new PDO('sqlite:' . __DIR__ . '/info.db');
        $row = $db->query('SELECT * FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $cached = is_array($row) ? array_merge($defaults, $row) : $defaults;
    } catch (Exception $e) {
        $cached = $defaults;
    }

    return $cached;
}

/**
 * Resolve configured printer target from business settings, with legacy IP fallback.
 *
 * @return array{connector: PrintConnector, label: string, is_network: bool}
 */
function receipt_create_printer_connector(?array $businessInfo = null): array
{
    $businessInfo = $businessInfo ?? receipt_load_business_info();
    $printerPort = trim((string) ($businessInfo['printer_port'] ?? 'COM4'));
    if ($printerPort === '') {
        $printerPort = 'COM4';
    }

    // host:port or host:port/path style network printer in printer_port field
    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d{1,5})$/', $printerPort, $m)) {
        $host = $m[1];
        $port = (int) $m[2];
        if ($port > 0 && $port <= 65535) {
            return [
                'connector' => new NetworkPrintConnector($host, $port),
                'label' => $host . ':' . $port,
                'is_network' => true,
            ];
        }
    }

  // Named Windows printer or COM/LPT/USB port from settings
    if ($printerPort !== '') {
        return [
            'connector' => new WindowsPrintConnector($printerPort),
            'label' => $printerPort,
            'is_network' => false,
        ];
    }

    // Legacy fallback based on client IP (older installs)
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '127.0.0.1';
    if ($clientIP === '192.168.178.87') {
        return [
            'connector' => new NetworkPrintConnector('192.168.1.7', 9100),
            'label' => 'POSPrinter POS-80C (legacy network)',
            'is_network' => true,
        ];
    }

    return [
        'connector' => new WindowsPrintConnector('XP-58SERIES'),
        'label' => 'XP-58SERIES (legacy default)',
        'is_network' => false,
    ];
}

/**
 * Pulse cash drawer on an open printer connection.
 */
function receipt_pulse_cash_drawer(Printer $printer): void
{
    $printer->pulse();
    $printer->initialize();
}
