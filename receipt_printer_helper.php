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
 * Installed Windows printers (cached per request).
 *
 * @return array<int, array{name: string, port: string, share: string}>
 */
function receipt_list_windows_printers(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    if (PHP_OS_FAMILY !== 'Windows') {
        return $cache;
    }

    $powershell = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (!is_file($powershell)) {
        $powershell = 'powershell';
    }
    $cmd = $powershell . ' -NoProfile -Command "Get-Printer | Select-Object Name,PortName,ShareName | ConvertTo-Json -Compress"';
    $json = @shell_exec($cmd);
    if (!is_string($json) || trim($json) === '') {
        return $cache;
    }

    $decoded = json_decode(trim($json), true);
    if (!is_array($decoded)) {
        return $cache;
    }
    if (isset($decoded['Name'])) {
        $decoded = [$decoded];
    }

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['Name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $cache[] = [
            'name' => $name,
            'port' => trim((string) ($row['PortName'] ?? '')),
            'share' => trim((string) ($row['ShareName'] ?? '')),
        ];
    }

    return $cache;
}

/**
 * Map a Windows port or printer name to a Mike42 WindowsPrintConnector target.
 */
function receipt_resolve_windows_printer_target(string $portOrName): ?string
{
    $portOrName = trim($portOrName);
    if ($portOrName === '') {
        return null;
    }

    foreach (receipt_list_windows_printers() as $printer) {
        if (strcasecmp($printer['port'], $portOrName) === 0) {
            if ($printer['share'] !== '') {
                return $printer['share'];
            }

            return $printer['name'];
        }
    }

    foreach (receipt_list_windows_printers() as $printer) {
        if (strcasecmp($printer['name'], $portOrName) === 0) {
            return $printer['share'] !== '' ? $printer['share'] : $printer['name'];
        }
        if ($printer['share'] !== '' && strcasecmp($printer['share'], $portOrName) === 0) {
            return $printer['share'];
        }
    }

    return null;
}

/**
 * Resolve configured printer target from business settings, with legacy IP fallback.
 *
 * @return array{connector: PrintConnector, label: string, is_network: bool}
 */
function receipt_create_printer_connector(?array $businessInfo = null): array
{
    $businessInfo = $businessInfo ?? receipt_load_business_info();
    $configuredPort = trim((string) ($businessInfo['printer_port'] ?? 'COM4'));
    $printerPort = $configuredPort !== '' ? $configuredPort : 'COM4';
    $connectorLabel = $printerPort;

    // host:port network printer in printer_port field
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

    // smb://computer/share — pass through to Mike42
    if (preg_match('/^smb:\/\//i', $printerPort)) {
        return [
            'connector' => new WindowsPrintConnector($printerPort),
            'label' => $printerPort,
            'is_network' => false,
        ];
    }

    // COM/LPT: direct port write (Mike42 local mode)
    if (preg_match('/^(?:COM\d+|LPT\d+)$/i', $printerPort)) {
        return [
            'connector' => new WindowsPrintConnector(strtoupper($printerPort)),
            'label' => strtoupper($printerPort),
            'is_network' => false,
        ];
    }

    // USB port or printer name: resolve to installed Windows printer share/name
    if (PHP_OS_FAMILY === 'Windows') {
        $resolved = receipt_resolve_windows_printer_target($printerPort);
        if ($resolved !== null) {
            $connectorLabel = $resolved;
            if (strcasecmp($resolved, $printerPort) !== 0) {
                $connectorLabel = $printerPort . ' -> ' . $resolved;
            }
            $printerPort = $resolved;
        } elseif (preg_match('/^USB\d+$/i', $printerPort)) {
            throw new Exception(
                'No printer found on Windows port ' . $configuredPort . '. '
                . 'Open Settings and set Printer Port to your receipt printer name (e.g. XP-58SERIES) '
                . 'or the correct USB port shown in Windows printer properties.'
            );
        }
    }

    return [
        'connector' => new WindowsPrintConnector($printerPort),
        'label' => $connectorLabel,
        'is_network' => false,
    ];
}

/**
 * Pulse cash drawer on an open printer connection.
 */
function receipt_pulse_cash_drawer(Printer $printer): void
{
    // Pin 0 = drawer kick connector pin 2 (Epson/XP-58 default). Pulse then feed to flush bytes.
    $printer->pulse(0, 120, 240);
    $printer->feed(1);
}
