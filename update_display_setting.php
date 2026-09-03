<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

try {
    // Connect to the SQLite database
    $pdo = new PDO('sqlite:' . __DIR__ . '/pos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the JSON data from the request body
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    $hide_available_quantity = $data['hide_available_quantity'] ?? null;
    $skip_stock_checks = $data['skip_stock_checks'] ?? null;
    $use_qz_tray = $data['use_qz_tray'] ?? null;
    $kitchen_printer_ip = array_key_exists('kitchen_printer_ip', $data) ? $data['kitchen_printer_ip'] : null;
    $kitchen_printer_port = $data['kitchen_printer_port'] ?? null;
    $cashier_idle_timeout_seconds = $data['cashier_idle_timeout_seconds'] ?? null;
    $cashier_inactivity_enabled = $data['cashier_inactivity_enabled'] ?? null;
    $receipt_paper_width_mm = $data['receipt_paper_width_mm'] ?? null;
    $pole_display_enabled = array_key_exists('pole_display_enabled', $data) ? $data['pole_display_enabled'] : null;
    $pole_display_port = array_key_exists('pole_display_port', $data) ? $data['pole_display_port'] : null;
    $pole_display_baud = array_key_exists('pole_display_baud', $data) ? $data['pole_display_baud'] : null;
    $pole_display_mode = array_key_exists('pole_display_mode', $data) ? $data['pole_display_mode'] : null;

    require_once __DIR__ . '/pole_display_settings_helper.php';
    ensurePoleDisplaySettingsColumns($pdo);

    // Add columns if they don't exist
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN hide_available_quantity BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists, continue
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN skip_stock_checks BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists, continue
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists, continue
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_ip TEXT");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_port INTEGER NOT NULL DEFAULT 9100");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN cashier_idle_timeout_seconds INTEGER NOT NULL DEFAULT 120");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN cashier_inactivity_enabled BOOLEAN NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN receipt_paper_width_mm INTEGER NOT NULL DEFAULT 58");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN gratuity_percent REAL NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN gratuity_default_enabled INTEGER NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN gratuity_default_include_in_total INTEGER NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN credit_interest_enabled INTEGER NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN credit_interest_rate REAL NOT NULL DEFAULT 18");
    } catch (PDOException $e) {
    }

    $gratuity_percent = array_key_exists('gratuity_percent', $data) ? $data['gratuity_percent'] : null;
    $credit_interest_enabled = $data['credit_interest_enabled'] ?? null;
    $credit_interest_rate = array_key_exists('credit_interest_rate', $data) ? $data['credit_interest_rate'] : null;
    $gratuity_default_enabled = $data['gratuity_default_enabled'] ?? null;
    // Update the setting(s) in the database (only update what was sent)
    if ($hide_available_quantity !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET hide_available_quantity = ? WHERE id = 1");
        $stmt->execute([$hide_available_quantity]);
    }
    if ($skip_stock_checks !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET skip_stock_checks = ? WHERE id = 1");
        $stmt->execute([$skip_stock_checks]);
    }

    if ($use_qz_tray !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET use_qz_tray = ? WHERE id = 1");
        $stmt->execute([$use_qz_tray]);
    }

    if ($kitchen_printer_ip !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET kitchen_printer_ip = ? WHERE id = 1");
        $stmt->execute([trim((string)$kitchen_printer_ip)]);
    }
    if ($kitchen_printer_port !== null) {
        $p = (int)$kitchen_printer_port;
        if ($p <= 0 || $p > 65535) {
            $p = 9100;
        }
        $stmt = $pdo->prepare("UPDATE product_settings SET kitchen_printer_port = ? WHERE id = 1");
        $stmt->execute([$p]);
    }
    if ($cashier_inactivity_enabled !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET cashier_inactivity_enabled = ? WHERE id = 1");
        $stmt->execute([(int) ((bool) $cashier_inactivity_enabled)]);
    }
    if ($cashier_idle_timeout_seconds !== null) {
        $idle = (int) $cashier_idle_timeout_seconds;
        if ($idle < 30) {
            $idle = 30;
        }
        if ($idle > 3600) {
            $idle = 3600;
        }
        $stmt = $pdo->prepare("UPDATE product_settings SET cashier_idle_timeout_seconds = ? WHERE id = 1");
        $stmt->execute([$idle]);
    }
    if ($receipt_paper_width_mm !== null) {
        $paperWidth = (int)$receipt_paper_width_mm;
        if ($paperWidth !== 80) {
            $paperWidth = 58;
        }
        $stmt = $pdo->prepare("UPDATE product_settings SET receipt_paper_width_mm = ? WHERE id = 1");
        $stmt->execute([$paperWidth]);
    }

    if ($gratuity_percent !== null) {
        $gp = round(floatval($gratuity_percent), 2);
        if ($gp < 0) {
            $gp = 0;
        }
        if ($gp > 100) {
            $gp = 100;
        }
        $stmt = $pdo->prepare("UPDATE product_settings SET gratuity_percent = ? WHERE id = 1");
        $stmt->execute([$gp]);
    }
    if ($gratuity_default_enabled !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET gratuity_default_enabled = ? WHERE id = 1");
        $stmt->execute([(int) ((bool) $gratuity_default_enabled)]);
    }
    if ($credit_interest_enabled !== null) {
        $stmt = $pdo->prepare('UPDATE product_settings SET credit_interest_enabled = ? WHERE id = 1');
        $stmt->execute([(int) ((bool) $credit_interest_enabled)]);
    }
    if ($credit_interest_rate !== null) {
        $rate = round(floatval($credit_interest_rate), 2);
        if ($rate < 0) {
            $rate = 0;
        }
        if ($rate > 100) {
            $rate = 100;
        }
        $stmt = $pdo->prepare('UPDATE product_settings SET credit_interest_rate = ? WHERE id = 1');
        $stmt->execute([$rate]);
    }
    if ($pole_display_enabled !== null) {
        $stmt = $pdo->prepare('UPDATE product_settings SET pole_display_enabled = ? WHERE id = 1');
        $stmt->execute([(int) ((bool) $pole_display_enabled)]);
    }
    if ($pole_display_port !== null) {
        $port = strtoupper(trim((string) $pole_display_port));
        if ($port !== 'AUTO' && !preg_match('/^COM\d+$/', $port) && $port !== '') {
            $port = '';
        }
        $stmt = $pdo->prepare('UPDATE product_settings SET pole_display_port = ? WHERE id = 1');
        $stmt->execute([$port]);
    }
    if ($pole_display_baud !== null) {
        $baud = (int) $pole_display_baud;
        if (!in_array($baud, [2400, 4800, 9600, 19200], true)) {
            $baud = 9600;
        }
        $stmt = $pdo->prepare('UPDATE product_settings SET pole_display_baud = ? WHERE id = 1');
        $stmt->execute([$baud]);
    }
    if ($pole_display_mode !== null) {
        $mode = strtolower(trim((string) $pole_display_mode)) === 'pst' ? 'pst' : 'epson';
        $stmt = $pdo->prepare('UPDATE product_settings SET pole_display_mode = ? WHERE id = 1');
        $stmt->execute([$mode]);
    }
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => true, 'message' => 'Setting updated successfully']);
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;



