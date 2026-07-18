<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

require_once __DIR__ . '/../manager_pin_helper.php';
?>

<?php
// Initialize variables
$successMessage = '';
$errorMessage = '';
$businessInfo = [];
$printerPort = 'COM4'; // Default value
$closingTime = '22:00'; // Default closing time
$vatInclusive = 'exclusive'; // Default VAT setting (exclusive)
$vatRate = 15.0; // Default Namibian VAT rate (15%)
$allowedVatModes = ['exclusive', 'inclusive', 'none'];
$manager_pin_configured = false;
try {
    $manager_pin_configured = managerVoidPinIsConfigured();
} catch (Throwable $e) {
}

// Connect to database
try {
    $db = new PDO('sqlite:../info.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create business_info table if it doesn't exist and add closing_time column if needed
    $db->exec("CREATE TABLE IF NOT EXISTS business_info (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        location TEXT NOT NULL,
        phone TEXT NOT NULL,
        header_custom_text TEXT NOT NULL DEFAULT '',
        footer_text TEXT NOT NULL,
        printer_port TEXT NOT NULL DEFAULT 'COM4',
        closing_time TEXT NOT NULL DEFAULT '22:00',
        vat_inclusive TEXT NOT NULL DEFAULT 'exclusive',
        vat_rate REAL NOT NULL DEFAULT 15.0
    )");
    
    // Check if columns exist, if not add them
    $columns = $db->query("PRAGMA table_info(business_info)")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    
    if(!in_array('closing_time', $columnNames)) {
        $db->exec("ALTER TABLE business_info ADD COLUMN closing_time TEXT NOT NULL DEFAULT '22:00'");
    }
    
    if(!in_array('vat_inclusive', $columnNames)) {
        $db->exec("ALTER TABLE business_info ADD COLUMN vat_inclusive TEXT NOT NULL DEFAULT 'exclusive'");
    }
    
    if(!in_array('vat_rate', $columnNames)) {
        $db->exec("ALTER TABLE business_info ADD COLUMN vat_rate REAL NOT NULL DEFAULT 15.0");
    }

    if(!in_array('header_custom_text', $columnNames)) {
        $db->exec("ALTER TABLE business_info ADD COLUMN header_custom_text TEXT NOT NULL DEFAULT ''");
    }

    if(!in_array('logo_path', $columnNames)) {
        $db->exec("ALTER TABLE business_info ADD COLUMN logo_path TEXT NOT NULL DEFAULT ''");
    }

    ensureManagerVoidPinColumn($db);

    // Save / change manager void PIN (separate form)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manager_void_pin'])) {
        $newPin = trim($_POST['manager_void_pin_new'] ?? '');
        $confirm = trim($_POST['manager_void_pin_confirm'] ?? '');
        if (strlen($newPin) < 4) {
            $errorMessage = 'Manager void PIN must be at least 4 characters.';
        } elseif ($newPin !== $confirm) {
            $errorMessage = 'Manager void PIN confirmation does not match.';
        } else {
            try {
                setManagerVoidPin($newPin);
                $manager_pin_configured = true;
                $successMessage = 'Manager void PIN saved successfully.';
            } catch (Throwable $e) {
                $errorMessage = $e->getMessage();
            }
        }
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_manager_void_pin']) && !isset($_POST['update_receipt_setting']) && !isset($_POST['import_csv']) && !isset($_POST['update_cashier_permissions']) && !isset($_POST['update_cashier_inactivity']) && !isset($_POST['update_waitress_permissions']) && !isset($_POST['update_pos_interface'])) {
        // Validate inputs
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $header_custom_text = trim($_POST['header_custom_text'] ?? '');
        $footer_text = trim($_POST['footer_text'] ?? '');
        $printer_port = trim($_POST['printer_port'] ?? 'COM4');
        $closing_time = trim($_POST['closing_time'] ?? '22:00');
        $vat_inclusive = trim($_POST['vat_inclusive'] ?? 'exclusive');
        if (!in_array($vat_inclusive, $allowedVatModes, true)) {
            $vat_inclusive = 'exclusive';
        }
        $vat_rate = floatval($_POST['vat_rate'] ?? 15.0);
        $convertInventoryPrices = !empty($_POST['convert_inventory_prices']);
        
        // Validate VAT rate (should be between 0 and 100)
        if ($vat_rate < 0 || $vat_rate > 100) {
            $errorMessage = 'VAT rate must be between 0 and 100.';
        } elseif (empty($name) || empty($location)) {
            $errorMessage = 'Business name and location are required fields.';
        } else {
            $logoPath = '';
            $currentRow = $db->query("SELECT logo_path FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $logoPath = trim((string)($currentRow['logo_path'] ?? ''));

            if (!empty($_POST['remove_logo'])) {
                if ($logoPath !== '') {
                    $oldLogoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoPath);
                    if (is_file($oldLogoFile)) {
                        @unlink($oldLogoFile);
                    }
                }
                $logoPath = '';
            }

            if (isset($_FILES['business_logo']) && is_array($_FILES['business_logo']) && ($_FILES['business_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $uploadTmp = $_FILES['business_logo']['tmp_name'];
                $maxBytes = 2 * 1024 * 1024;
                if (($_FILES['business_logo']['size'] ?? 0) > $maxBytes) {
                    $errorMessage = 'Logo file is too large. Maximum size is 2 MB.';
                } else {
                    $allowedMimes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                    ];
                    $detectedMime = '';
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo) {
                            $detectedMime = (string)finfo_file($finfo, $uploadTmp);
                            finfo_close($finfo);
                        }
                    }
                    if ($detectedMime === '' || !isset($allowedMimes[$detectedMime])) {
                        $errorMessage = 'Invalid logo file. Please upload a JPG, PNG, GIF, or WebP image.';
                    } elseif (!function_exists('imagecreatetruecolor')) {
                        $errorMessage = 'PHP GD extension is required to process the logo image.';
                    } else {
                        $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'business';
                        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                            $errorMessage = 'Could not create logo upload folder.';
                        } else {
                            $src = null;
                            if ($detectedMime === 'image/jpeg') {
                                $src = @imagecreatefromjpeg($uploadTmp);
                            } elseif ($detectedMime === 'image/png') {
                                $src = @imagecreatefrompng($uploadTmp);
                            } elseif ($detectedMime === 'image/gif') {
                                $src = @imagecreatefromgif($uploadTmp);
                            } elseif ($detectedMime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                                $src = @imagecreatefromwebp($uploadTmp);
                            }
                            if (!$src) {
                                $errorMessage = 'Could not read the uploaded logo image.';
                            } else {
                                $destFile = $uploadDir . DIRECTORY_SEPARATOR . 'logo.png';
                                $srcW = imagesx($src);
                                $srcH = imagesy($src);
                                $flat = imagecreatetruecolor($srcW, $srcH);
                                if ($flat === false) {
                                    $errorMessage = 'Could not prepare the logo image.';
                                    imagedestroy($src);
                                } else {
                                    $white = imagecolorallocate($flat, 255, 255, 255);
                                    imagefilledrectangle($flat, 0, 0, $srcW, $srcH, $white);
                                    imagealphablending($flat, true);
                                    imagealphablending($src, true);
                                    imagecopy($flat, $src, 0, 0, 0, 0, $srcW, $srcH);
                                    imagedestroy($src);
                                    $src = $flat;
                                    if (!@imagepng($src, $destFile)) {
                                        $errorMessage = 'Failed to save the logo image.';
                                    } else {
                                        if ($logoPath !== '' && $logoPath !== 'uploads/business/logo.png') {
                                            $oldLogoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoPath);
                                            if (is_file($oldLogoFile)) {
                                                @unlink($oldLogoFile);
                                            }
                                        }
                                        $logoPath = 'uploads/business/logo.png';
                                    }
                                    imagedestroy($src);
                                }
                            }
                        }
                    }
                }
            }

            if ($errorMessage !== '') {
                // Skip DB update when logo validation failed
            } else {
            // Get current VAT settings before updating
            $currentBusinessInfo = $db->query("SELECT vat_inclusive, vat_rate FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $oldVatInclusive = $currentBusinessInfo ? ($currentBusinessInfo['vat_inclusive'] ?? 'exclusive') : 'exclusive';
            if (!in_array($oldVatInclusive, $allowedVatModes, true)) {
                $oldVatInclusive = 'exclusive';
            }
            $oldVatRate = $currentBusinessInfo ? (floatval($currentBusinessInfo['vat_rate'] ?? 15.0)) : 15.0;
            
            // Check if a record exists
            $check = $db->query("SELECT COUNT(*) FROM business_info")->fetchColumn();
            
            if ($check > 0) {
                // Update existing record
                $stmt = $db->prepare("UPDATE business_info SET 
                    name = :name, 
                    location = :location, 
                    phone = :phone, 
                    header_custom_text = :header_custom_text,
                    footer_text = :footer_text,
                    printer_port = :printer_port,
                    closing_time = :closing_time,
                    vat_inclusive = :vat_inclusive,
                    vat_rate = :vat_rate,
                    logo_path = :logo_path
                    WHERE id = 1");
            } else {
                // Insert new record
                $stmt = $db->prepare("INSERT INTO business_info 
                    (name, location, phone, header_custom_text, footer_text, printer_port, closing_time, vat_inclusive, vat_rate, logo_path) 
                    VALUES (:name, :location, :phone, :header_custom_text, :footer_text, :printer_port, :closing_time, :vat_inclusive, :vat_rate, :logo_path)");
            }
            
            $stmt->execute([
                ':name' => $name,
                ':location' => $location,
                ':phone' => $phone,
                ':header_custom_text' => $header_custom_text,
                ':footer_text' => $footer_text,
                ':printer_port' => $printer_port,
                ':closing_time' => $closing_time,
                ':vat_inclusive' => $vat_inclusive,
                ':vat_rate' => $vat_rate,
                ':logo_path' => $logoPath
            ]);
            
            $modeChanged = ($oldVatInclusive !== $vat_inclusive);
            $inclusiveRateChanged = ($oldVatRate != $vat_rate && $vat_inclusive === 'inclusive');
            
            // Convert product prices only if requested (optional)
            if ($convertInventoryPrices && $modeChanged) {
                try {
                    $posDb = new PDO('sqlite:../pos.db');
                    $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Get all products
                    $products = $posDb->query("SELECT id, price FROM products")->fetchAll(PDO::FETCH_ASSOC);
                    $updateStmt = $posDb->prepare("UPDATE products SET price = :price WHERE id = :id");
                    
                    $convertedCount = 0;
                    foreach ($products as $product) {
                        $oldPrice = floatval($product['price']);
                        $newPrice = $oldPrice;
                        
                        if ($oldVatInclusive === 'exclusive' && $vat_inclusive === 'inclusive') {
                            $newPrice = $oldPrice * (1 + ($vat_rate / 100));
                        } elseif ($oldVatInclusive === 'inclusive' && $vat_inclusive === 'exclusive') {
                            $newPrice = $oldPrice / (1 + ($vat_rate / 100));
                        } elseif ($oldVatInclusive === 'inclusive' && $vat_inclusive === 'none') {
                            $newPrice = $oldPrice / (1 + ($oldVatRate / 100));
                        } elseif ($oldVatInclusive === 'none' && $vat_inclusive === 'inclusive') {
                            $newPrice = $oldPrice * (1 + ($vat_rate / 100));
                        }
                        // exclusive <-> none: no change (both use ex-VAT shelf prices)
                        
                        $newPrice = round($newPrice, 2);
                        
                        $updateStmt->execute([
                            ':price' => $newPrice,
                            ':id' => $product['id']
                        ]);
                        $convertedCount++;
                    }
                    
                    $label = function ($m) {
                        if ($m === 'exclusive') {
                            return 'VAT excluded';
                        }
                        if ($m === 'inclusive') {
                            return 'VAT included';
                        }
                        return 'No VAT';
                    };
                    $successMessage = 'Business information updated successfully! ' . $convertedCount . ' product price(s) converted from ' .
                        $label($oldVatInclusive) . ' to ' . $label($vat_inclusive) . '.';
                } catch (PDOException $e) {
                    $successMessage = 'Business information updated successfully! However, there was an error converting product prices: ' . $e->getMessage();
                }
            } elseif ($convertInventoryPrices && $inclusiveRateChanged) {
                // VAT rate changed and prices are inclusive - need to recalculate
                try {
                    $posDb = new PDO('sqlite:../pos.db');
                    $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // First convert back to exclusive using old rate, then to inclusive with new rate
                    $products = $posDb->query("SELECT id, price FROM products")->fetchAll(PDO::FETCH_ASSOC);
                    $updateStmt = $posDb->prepare("UPDATE products SET price = :price WHERE id = :id");
                    
                    $convertedCount = 0;
                    foreach ($products as $product) {
                        $currentPrice = floatval($product['price']);
                        // Convert to exclusive first, then to inclusive with new rate
                        $exclusivePrice = $currentPrice / (1 + ($oldVatRate / 100));
                        $newPrice = $exclusivePrice * (1 + ($vat_rate / 100));
                        $newPrice = round($newPrice, 2);
                        
                        $updateStmt->execute([
                            ':price' => $newPrice,
                            ':id' => $product['id']
                        ]);
                        $convertedCount++;
                    }
                    
                    $successMessage = 'Business information updated successfully! ' . $convertedCount . ' product price(s) recalculated with new VAT rate.';
                } catch (PDOException $e) {
                    $successMessage = 'Business information updated successfully! However, there was an error recalculating product prices: ' . $e->getMessage();
                }
            } else {
                if (!$convertInventoryPrices && ($modeChanged || $inclusiveRateChanged)) {
                    $successMessage = 'Business information updated successfully! VAT settings were saved. Product prices in your inventory were not changed — enable "Adjust inventory prices" and save again if you want all shelf prices recalculated automatically.';
                } else {
                    $successMessage = 'Business information updated successfully!';
                }
            }
            }
        }
    }
    
    // Handle cashier inactivity settings form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cashier_inactivity'])) {
        try {
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $posDb->exec("CREATE TABLE IF NOT EXISTS product_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                show_all_products BOOLEAN NOT NULL DEFAULT 0,
                default_print_receipt BOOLEAN NOT NULL DEFAULT 0
            )");

            try {
                $posDb->exec("ALTER TABLE product_settings ADD COLUMN cashier_idle_timeout_seconds INTEGER NOT NULL DEFAULT 120");
            } catch (PDOException $e) {
            }
            try {
                $posDb->exec("ALTER TABLE product_settings ADD COLUMN cashier_inactivity_enabled BOOLEAN NOT NULL DEFAULT 1");
            } catch (PDOException $e) {
            }
            foreach ([
                "ALTER TABLE product_settings ADD COLUMN inactivity_role_admin INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE product_settings ADD COLUMN inactivity_role_manager INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE product_settings ADD COLUMN inactivity_role_cashier INTEGER NOT NULL DEFAULT 1",
                "ALTER TABLE product_settings ADD COLUMN inactivity_role_waitress INTEGER NOT NULL DEFAULT 0",
            ] as $inactivityRoleSql) {
                try {
                    $posDb->exec($inactivityRoleSql);
                } catch (PDOException $e) {
                }
            }

            $checkStmt = $posDb->query("SELECT COUNT(*) FROM product_settings")->fetchColumn();
            if ($checkStmt == 0) {
                $posDb->exec("INSERT INTO product_settings (show_all_products, default_print_receipt, cashier_inactivity_enabled, cashier_idle_timeout_seconds) VALUES (0, 0, 1, 120)");
            }

            $cashierInactivityEnabled = isset($_POST['cashier_inactivity_enabled']) ? 1 : 0;
            $inactivityRoleAdmin = isset($_POST['inactivity_role_admin']) ? 1 : 0;
            $inactivityRoleManager = isset($_POST['inactivity_role_manager']) ? 1 : 0;
            $inactivityRoleCashier = isset($_POST['inactivity_role_cashier']) ? 1 : 0;
            $inactivityRoleWaitress = isset($_POST['inactivity_role_waitress']) ? 1 : 0;
            $idleSeconds = (int) ($_POST['cashier_idle_timeout_seconds'] ?? 120);
            if ($idleSeconds < 30) {
                $idleSeconds = 30;
            }
            if ($idleSeconds > 3600) {
                $idleSeconds = 3600;
            }

            $updateStmt = $posDb->prepare("UPDATE product_settings SET cashier_inactivity_enabled = ?, cashier_idle_timeout_seconds = ?, inactivity_role_admin = ?, inactivity_role_manager = ?, inactivity_role_cashier = ?, inactivity_role_waitress = ? WHERE id = 1");
            $updateStmt->execute([
                $cashierInactivityEnabled,
                $idleSeconds,
                $inactivityRoleAdmin,
                $inactivityRoleManager,
                $inactivityRoleCashier,
                $inactivityRoleWaitress,
            ]);
            require_once __DIR__ . '/../inactivity_settings_helper.php';
            $dbPath = realpath(__DIR__ . '/../pos.db') ?: (__DIR__ . '/../pos.db');
            debugInactivityLog('admin/business_settings.php:save', 'saved inactivity settings', [
                'db_path' => $dbPath,
                'rows_affected' => $updateStmt->rowCount(),
                'enabled' => $cashierInactivityEnabled,
                'idle_seconds' => $idleSeconds,
                'roles' => [
                    'admin' => $inactivityRoleAdmin,
                    'manager' => $inactivityRoleManager,
                    'cashier' => $inactivityRoleCashier,
                    'waitress' => $inactivityRoleWaitress,
                ],
            ], 'E');

            $successMessage = 'Inactivity logout settings updated successfully!';
        } catch (PDOException $e) {
            $errorMessage = 'Error updating cashier inactivity settings: ' . $e->getMessage();
        }
        if (isset($_POST['return_to']) && $_POST['return_to'] === 'display') {
            if (!empty($successMessage)) {
                $_SESSION['settings_flash_success'] = $successMessage;
            }
            if (!empty($errorMessage)) {
                $_SESSION['settings_flash_error'] = $errorMessage;
            }
            header('Location: settings?s=display');
            exit;
        }

    }

    // Handle receipt settings form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_receipt_setting'])) {
        try {
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Ensure table and column exist
            $posDb->exec("CREATE TABLE IF NOT EXISTS product_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                show_all_products BOOLEAN NOT NULL DEFAULT 0,
                default_print_receipt BOOLEAN NOT NULL DEFAULT 0
            )");
            
            // Check if row exists
            $checkStmt = $posDb->query("SELECT COUNT(*) FROM product_settings")->fetchColumn();
            if ($checkStmt == 0) {
                $posDb->exec("INSERT INTO product_settings (show_all_products, default_print_receipt) VALUES (0, 0)");
            }
            
            // Ensure columns exist
            try {
                $posDb->exec("ALTER TABLE product_settings ADD COLUMN drawer_open_on_checkout TEXT NOT NULL DEFAULT 'on_ok'");
            } catch (PDOException $e) {
                // Column already exists
            }
            try {
                $posDb->exec("ALTER TABLE product_settings ADD COLUMN show_reverse_transaction BOOLEAN NOT NULL DEFAULT 1");
            } catch (PDOException $e) {
                // Column already exists
            }
            
            // Update settings
            $newPrintReceipt = isset($_POST['default_print_receipt']) ? 1 : 0;
            $newDrawerSetting = isset($_POST['drawer_open_on_checkout']) ? trim($_POST['drawer_open_on_checkout']) : 'on_ok';
            $newShowReverse = isset($_POST['show_reverse_transaction']) ? 1 : 0;
            
            // Validate drawer setting
            if (!in_array($newDrawerSetting, ['on_ok', 'on_checkout'], true)) {
                $newDrawerSetting = 'on_ok';
            }
            
            $updateStmt = $posDb->prepare("UPDATE product_settings SET default_print_receipt = ?, drawer_open_on_checkout = ?, show_reverse_transaction = ? WHERE id = 1");
            $updateStmt->execute([$newPrintReceipt, $newDrawerSetting, $newShowReverse]);
            $successMessage = 'Receipt settings updated successfully!';
        } catch(PDOException $e) {
            $errorMessage = 'Error updating receipt settings: ' . $e->getMessage();
        }
        if (isset($_POST['return_to']) && $_POST['return_to'] === 'display') {
            if (!empty($successMessage)) {
                $_SESSION['settings_flash_success'] = $successMessage;
            }
            if (!empty($errorMessage)) {
                $_SESSION['settings_flash_error'] = $errorMessage;
            }
            header('Location: settings?s=display');
            exit;
        }
    }

    // Handle POS interface form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pos_interface'])) {
        try {
            require_once __DIR__ . '/../touch_keyboard_settings_helper.php';
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $checkStmt = $posDb->query('SELECT COUNT(*) FROM product_settings')->fetchColumn();
            if ((int) $checkStmt === 0) {
                $posDb->exec('INSERT INTO product_settings (show_all_products, default_print_receipt) VALUES (0, 0)');
            }

            ensureTouchKeyboardSettingsColumn($posDb);
            $enabled = isset($_POST['touch_keyboard_enabled']) ? 1 : 0;
            $posDb->prepare('UPDATE product_settings SET touch_keyboard_enabled = ? WHERE id = 1')->execute([$enabled]);
            $successMessage = 'POS interface settings updated successfully!';
        } catch (PDOException $e) {
            $errorMessage = 'Error updating POS interface settings: ' . $e->getMessage();
        }
        if (isset($_POST['return_to']) && $_POST['return_to'] === 'display') {
            if (!empty($successMessage)) {
                $_SESSION['settings_flash_success'] = $successMessage;
            }
            if (!empty($errorMessage)) {
                $_SESSION['settings_flash_error'] = $errorMessage;
            }
            header('Location: settings?s=display');
            exit;
        }

    }

    // Handle waitress permissions form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_waitress_permissions'])) {
        try {
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            require_once __DIR__ . '/../tab_balance_helper.php';
            ensure_waitress_can_take_tab_payments_column($posDb);

            $checkStmt = $posDb->query('SELECT COUNT(*) FROM product_settings')->fetchColumn();
            if ((int) $checkStmt === 0) {
                $posDb->exec('INSERT INTO product_settings (show_all_products, default_print_receipt, waitress_can_take_tab_payments) VALUES (0, 0, 0)');
            }

            $enabled = isset($_POST['waitress_can_take_tab_payments']) ? 1 : 0;
            $posDb->prepare('UPDATE product_settings SET waitress_can_take_tab_payments = ? WHERE id = 1')->execute([$enabled]);
            $successMessage = 'Waitress permissions updated successfully!';
        } catch (PDOException $e) {
            $errorMessage = 'Error updating waitress permissions: ' . $e->getMessage();
        }
        if (isset($_POST['return_to']) && $_POST['return_to'] === 'display') {
            if (!empty($successMessage)) {
                $_SESSION['settings_flash_success'] = $successMessage;
            }
            if (!empty($errorMessage)) {
                $_SESSION['settings_flash_error'] = $errorMessage;
            }
            header('Location: settings?s=display');
            exit;
        }

    }
    
    // Handle CSV import
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
        try {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please select a CSV file to upload.');
            }
            
            $file = $_FILES['csv_file']['tmp_name'];
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Prepare insert statement
            $insertStmt = $posDb->prepare("
                INSERT INTO products (name, price, buying_price, barcode, category, quantity)
                VALUES (:name, :price, :buying_price, :barcode, :category, :quantity)
            ");
            
            $handle = fopen($file, 'r');
            if ($handle === false) {
                throw new Exception('Could not open CSV file.');
            }
            
            // Skip header row
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new Exception('CSV file appears to be empty or invalid.');
            }
            
            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            
            while (($data = fgetcsv($handle)) !== false) {
                // Skip empty rows
                if (empty(array_filter($data))) {
                    continue;
                }
                
                // Map CSV columns (0-indexed)
                // STOCKCODE, BARCODE, DESCRIPTION, PRICE, PRICEINC, PRICE2, PRICEINC2, PRICE3, PRICEINC3, UNITCOST, DEPARTMENT, CATEGORY, STOCKONHAND, SUPPLIER
                $description = trim($data[2] ?? '');
                $price = trim($data[4] ?? '0'); // PRICEINC (inclusive)
                $barcode = trim($data[1] ?? '');
                $unitcost = trim($data[9] ?? '0');
                $category = trim($data[11] ?? '');
                $stockonhand = trim($data[12] ?? '0');
                
                // Skip if no description
                if (empty($description)) {
                    $skipped++;
                    continue;
                }
                
                // Validate and convert values
                $price = floatval($price);
                $unitcost = floatval($unitcost);
                $stockonhand = intval($stockonhand);
                
                if ($price <= 0) {
                    $skipped++;
                    $errors[] = "Skipped '" . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . "': Invalid price";
                    continue;
                }
                
                // Check if product exists by name
                $checkStmt = $posDb->prepare("SELECT id FROM products WHERE name = ?");
                $checkStmt->execute([$description]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Update existing product
                    $updateStmt = $posDb->prepare("
                        UPDATE products 
                        SET price = :price, 
                            buying_price = :buying_price, 
                            barcode = :barcode, 
                            category = :category,
                            quantity = :quantity
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ':price' => $price,
                        ':buying_price' => $unitcost > 0 ? $unitcost : null,
                        ':barcode' => !empty($barcode) ? $barcode : null,
                        ':category' => !empty($category) ? $category : null,
                        ':quantity' => $stockonhand,
                        ':id' => $existing['id']
                    ]);
                    $updated++;
                } else {
                    // Insert new product
                    $insertStmt->execute([
                        ':name' => $description,
                        ':price' => $price,
                        ':buying_price' => $unitcost > 0 ? $unitcost : null,
                        ':barcode' => !empty($barcode) ? $barcode : null,
                        ':category' => !empty($category) ? $category : null,
                        ':quantity' => $stockonhand
                    ]);
                    $imported++;
                }
            }
            
            fclose($handle);
            
            $message = "Import completed! ";
            $message .= "Imported: {$imported} new products, ";
            $message .= "Updated: {$updated} existing products";
            if ($skipped > 0) {
                $message .= ", Skipped: {$skipped} rows";
            }
            if (!empty($errors) && count($errors) <= 10) {
                $message .= "<br><small>" . implode("<br>", $errors) . "</small>";
            } elseif (!empty($errors)) {
                $message .= "<br><small>" . count($errors) . " errors occurred. Check your data.</small>";
            }
            
            $successMessage = $message;
        } catch (Exception $e) {
            $errorMessage = 'Error importing CSV: ' . $e->getMessage();
        }
    }
    
    // Handle cashier permissions form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cashier_permissions'])) {
        try {
            // Create cashier_permissions table if it doesn't exist
            $db->exec("CREATE TABLE IF NOT EXISTS cashier_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                allow_tabs BOOLEAN NOT NULL DEFAULT 1,
                allow_transactions BOOLEAN NOT NULL DEFAULT 1,
                allow_credit_book BOOLEAN NOT NULL DEFAULT 1,
                allow_cash_inout BOOLEAN NOT NULL DEFAULT 1,
                allow_settings BOOLEAN NOT NULL DEFAULT 0,
                allow_menu BOOLEAN NOT NULL DEFAULT 1,
                allow_reports BOOLEAN NOT NULL DEFAULT 1
            )");
            foreach (['allow_menu', 'allow_reports'] as $permCol) {
                try {
                    $db->exec("ALTER TABLE cashier_permissions ADD COLUMN {$permCol} BOOLEAN NOT NULL DEFAULT 1");
                } catch (PDOException $e) {
                }
            }
            
            // Check if row exists
            $checkStmt = $db->query("SELECT COUNT(*) FROM cashier_permissions")->fetchColumn();
            if ($checkStmt == 0) {
                $db->exec("INSERT INTO cashier_permissions (allow_tabs, allow_transactions, allow_credit_book, allow_cash_inout, allow_settings, allow_menu, allow_reports) VALUES (1, 1, 1, 1, 0, 1, 1)");
            }
            
            // One permission per cashier sidebar item
            $allowMenu = isset($_POST['allow_menu']) ? 1 : 0;
            $allowTransactions = isset($_POST['allow_transactions']) ? 1 : 0;
            $allowReports = isset($_POST['allow_reports']) ? 1 : 0;
            $allowSettings = isset($_POST['allow_settings']) ? 1 : 0;
            
            $updateStmt = $db->prepare("UPDATE cashier_permissions SET 
                allow_menu = :allow_menu,
                allow_transactions = :allow_transactions,
                allow_reports = :allow_reports,
                allow_settings = :allow_settings,
                allow_tabs = :allow_tabs,
                allow_credit_book = :allow_credit_book,
                allow_cash_inout = :allow_cash_inout
                WHERE id = 1");
            $updateStmt->execute([
                ':allow_menu' => $allowMenu,
                ':allow_transactions' => $allowTransactions,
                ':allow_reports' => $allowReports,
                ':allow_settings' => $allowSettings,
                // Keep legacy columns in sync with Menu for older checks
                ':allow_tabs' => $allowMenu,
                ':allow_credit_book' => $allowMenu,
                ':allow_cash_inout' => $allowMenu
            ]);
            
            $successMessage = 'Cashier permissions updated successfully!';
        } catch(PDOException $e) {
            $errorMessage = 'Error updating cashier permissions: ' . $e->getMessage();
        }
        if (isset($_POST['return_to']) && $_POST['return_to'] === 'display') {
            if (!empty($successMessage)) {
                $_SESSION['settings_flash_success'] = $successMessage;
            }
            if (!empty($errorMessage)) {
                $_SESSION['settings_flash_error'] = $errorMessage;
            }
            header('Location: settings?s=display');
            exit;
        }

    }
    
    // Get current business info
    $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    // If no business info found, use defaults
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'header_custom_text' => '',
            'logo_path' => '',
            'footer_text' => 'Thank you for your purchase!',
            'printer_port' => 'COM4',
            'closing_time' => '22:00',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    } else {
        $printerPort = $businessInfo['printer_port'];
        $closingTime = $businessInfo['closing_time'] ?? '22:00';
        $vatInclusive = $businessInfo['vat_inclusive'] ?? 'exclusive';
        if (!in_array($vatInclusive, $allowedVatModes, true)) {
            $vatInclusive = 'exclusive';
        }
        $vatRate = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
    }
    
} catch (PDOException $e) {
    $errorMessage = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business info</title>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../navigation.js" async></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="../favicon.ico" type="image/png">
</head>
<body class="bg-gray-100">
    <!-- Toast Notification Container -->
    <div id="toast" class="fixed top-4 right-4 z-50 hidden max-w-xs w-full bg-white rounded-lg shadow-lg border-l-4 p-4 transition-transform duration-300 transform translate-x-full">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <div class="toast-icon"></div>
                <p class="toast-message text-sm"></p>
            </div>
            <button onclick="hideToast()" class="ml-4 text-gray-400 hover:text-gray-500">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="content flex-1 lg:ml-64">
            <div class="w-full px-4 lg:px-6 py-6">
                <div class="mb-6">
                    <a href="settings" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400 mb-4">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back to settings
                    </a>
                    <h1 class="text-2xl lg:text-3xl font-bold text-gray-900">Business info</h1>
                    <p class="text-sm text-gray-500 mt-1">Name, branding, VAT, manager void PIN, and product CSV import</p>
                </div>
                
                <?php if (!empty($successMessage)): ?>
                    <div class="bg-teal-50 border-l-4 border-teal-500 p-4 mb-6 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-teal-700"><?php echo $successMessage; ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errorMessage)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-red-700"><?php echo $errorMessage; ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4 lg:gap-5 mb-4">
                <form method="post" action="" enctype="multipart/form-data" class="contents">
                    <!-- Business details -->
                    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center shrink-0">
                                <i class="fas fa-store text-indigo-600"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Business details</h2>
                                <p class="text-xs text-gray-500">Name, location, phone &amp; closing time</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">Business Name</label>
                                <input type="text" id="name" name="name" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars($businessInfo['name']); ?>" required>
                            </div>
                            <div>
                                <label for="location" class="block text-sm font-medium text-gray-700">Business Location</label>
                                <input type="text" id="location" name="location" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars($businessInfo['location']); ?>" required>
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                <input type="text" id="phone" name="phone" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars($businessInfo['phone']); ?>">
                            </div>
                            <div>
                                <label for="closing_time" class="block text-sm font-medium text-gray-700">Business Closing Time</label>
                                <input type="time" id="closing_time" name="closing_time" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars($closingTime); ?>">
                                <p class="mt-1 text-xs text-gray-500">System alerts for transactions after this time.</p>
                            </div>
                        </div>
                    </section>

                    <!-- Receipt branding / printer -->
                    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center shrink-0">
                                <i class="fas fa-receipt text-blue-600"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Printer &amp; receipt branding</h2>
                                <p class="text-xs text-gray-500">Logo, header, footer &amp; printer port</p>
                            </div>
                        </div>
                        <?php
                        $currentLogoPath = trim((string)($businessInfo['logo_path'] ?? ''));
                        $logoPreviewUrl = '';
                        if ($currentLogoPath !== '') {
                            $logoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $currentLogoPath);
                            if (is_file($logoFile)) {
                                $logoPreviewUrl = '../' . str_replace('\\', '/', $currentLogoPath) . '?v=' . filemtime($logoFile);
                            }
                        }
                        ?>
                        <div class="space-y-4">
                            <div>
                                <label for="business_logo" class="block text-sm font-medium text-gray-700">Receipt Business Logo</label>
                                <p class="mt-1 text-xs text-gray-500 mb-2">PNG/JPG/GIF/WebP, max 2 MB. Printed at the top of receipts.</p>
                                <?php if ($logoPreviewUrl !== ''): ?>
                                    <div class="mb-3 flex flex-col sm:flex-row sm:items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                                        <img src="<?php echo htmlspecialchars($logoPreviewUrl); ?>" alt="Current business logo" class="h-16 w-auto max-w-[160px] object-contain bg-white border border-gray-200 rounded p-1">
                                        <label class="inline-flex items-center text-sm text-gray-700">
                                            <input type="checkbox" name="remove_logo" value="1" class="h-4 w-4 text-red-600 border-gray-300 rounded focus:ring-red-500 mr-2">
                                            Remove current logo
                                        </label>
                                    </div>
                                <?php endif; ?>
                                <input type="file" id="business_logo" name="business_logo" accept="image/jpeg,image/png,image/gif,image/webp"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
                            </div>
                            <div>
                                <label for="header_custom_text" class="block text-sm font-medium text-gray-700">Receipt Header Custom Text</label>
                                <input type="text" id="header_custom_text" name="header_custom_text" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars($businessInfo['header_custom_text'] ?? ''); ?>" placeholder="Optional text shown under business name">
                            </div>
                            <div>
                                <label for="footer_text" class="block text-sm font-medium text-gray-700">Receipt Footer Text</label>
                                <textarea id="footer_text" name="footer_text" rows="2" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($businessInfo['footer_text']); ?></textarea>
                            </div>
                            <div>
                                <label for="printer_port" class="block text-sm font-medium text-gray-700">Printer Port</label>
                                <select id="printer_port" name="printer_port" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                                    <?php
                                    $ports = ['COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'LPT1', 'USB001', '/dev/usb/lp0'];
                                    if (!in_array($printerPort, $ports)) {
                                        $ports[] = $printerPort;
                                    }
                                    foreach ($ports as $port) {
                                        $selected = ($port === $printerPort) ? 'selected' : '';
                                        echo "<option value=\"$port\" $selected>$port</option>";
                                    }
                                    ?>
                                </select>
                                <div class="mt-2">
                                    <label for="custom_port" class="block text-sm font-medium text-gray-700">Or enter custom port:</label>
                                    <div class="mt-1 flex rounded-md shadow-sm">
                                        <input type="text" id="custom_port" class="focus:ring-teal-500 focus:border-teal-500 flex-1 block w-full rounded-none rounded-l-md sm:text-sm border-gray-300" placeholder="Custom port name">
                                        <button type="button" class="inline-flex items-center px-3 py-2 border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-sm rounded-r-md hover:bg-gray-100" onclick="addCustomPort()">Add</button>
                                    </div>
                                </div>
                               
                            </div>
                        </div>
                    </section>

                    <!-- VAT -->
                    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6 flex flex-col">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center shrink-0">
                                <i class="fas fa-percent text-amber-600"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Namibian VAT Settings</h2>
                                <p class="text-xs text-gray-500">VAT mode, rate &amp; inventory price conversion</p>
                            </div>
                        </div>
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 mb-4 rounded-md">
                            <p class="text-sm text-yellow-700">
                                <strong>Important:</strong> If you change the VAT mode or the VAT rate (while on VAT included), you can optionally recalculate all product prices below. Back up your data before using that option.
                            </p>
                        </div>
                        <div class="space-y-4 flex-1">
                            <div>
                                <label for="vat_inclusive" class="block text-sm font-medium text-gray-700 mb-2">VAT on sales</label>
                                <select id="vat_inclusive" name="vat_inclusive" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                                    <option value="exclusive" <?php echo ($vatInclusive === 'exclusive') ? 'selected' : ''; ?>>VAT excluded (ex VAT / add VAT at sale)</option>
                                    <option value="inclusive" <?php echo ($vatInclusive === 'inclusive') ? 'selected' : ''; ?>>VAT included (shelf prices include VAT; receipt shows embedded VAT in brackets)</option>
                                    <option value="none" <?php echo ($vatInclusive === 'none') ? 'selected' : ''; ?>>No VAT</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">
                                    <?php if ($vatInclusive === 'exclusive'): ?>
                                        Prices are ex VAT; VAT is applied according to your rate. Receipt does not list a VAT amount line.
                                    <?php elseif ($vatInclusive === 'inclusive'): ?>
                                        Prices include VAT. The receipt shows the included VAT amount (in brackets) calculated from the total and your VAT rate.
                                    <?php else: ?>
                                        No VAT; prices have no tax component. Receipt does not add VAT.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <label for="vat_rate" class="block text-sm font-medium text-gray-700">VAT Rate (%)</label>
                                <input type="number" id="vat_rate" name="vat_rate" step="0.01" min="0" max="100"
                                       class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars(number_format($vatRate, 2, '.', '')); ?>" required>
                                <p class="mt-1 text-xs text-gray-500">Default is 15% for Namibia.</p>
                            </div>
                            <div class="flex items-start p-4 bg-gray-50 border border-gray-200 rounded-md">
                                <div class="flex items-center h-5">
                                    <input id="convert_inventory_prices" name="convert_inventory_prices" type="checkbox" value="1"
                                           class="h-4 w-4 text-teal-600 border-gray-300 rounded focus:ring-teal-500">
                                </div>
                                <div class="ml-3 text-sm">
                                    <label for="convert_inventory_prices" class="font-medium text-gray-800">Adjust inventory prices to match this save</label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end">
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                <i class="fas fa-check mr-2"></i>
                                Save Settings
                            </button>
                        </div>
                    </section>
                </form>

                <!-- Manager void PIN -->
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6 flex flex-col">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center shrink-0">
                            <i class="fas fa-key text-amber-600"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">Manager void PIN</h2>
                            <p class="text-xs text-gray-500">Required to void/delete transactions</p>
                        </div>
                    </div>
                    <?php if ($manager_pin_configured): ?>
                    <p class="text-sm text-teal-700 mb-4 font-medium">A manager PIN is currently set. Enter a new PIN below to change it.</p>
                    <?php else: ?>
                    <p class="text-sm text-amber-700 mb-4 font-medium">No PIN set yet — voiding from reports and related actions will be blocked until you set one.</p>
                    <?php endif; ?>
                    <form method="POST" action="" class="space-y-4 flex-1 flex flex-col" autocomplete="off">
                        <input type="hidden" name="save_manager_void_pin" value="1">
                        <div>
                            <label for="manager_void_pin_new" class="block text-sm font-medium text-gray-700 mb-1">New PIN</label>
                            <input type="password" name="manager_void_pin_new" id="manager_void_pin_new" autocomplete="new-password" inputmode="numeric" minlength="4" required
                                class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        </div>
                        <div>
                            <label for="manager_void_pin_confirm" class="block text-sm font-medium text-gray-700 mb-1">Confirm PIN</label>
                            <input type="password" name="manager_void_pin_confirm" id="manager_void_pin_confirm" autocomplete="new-password" inputmode="numeric" minlength="4" required
                                class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        </div>
                        <div class="mt-auto pt-3 flex justify-end">
                            <button type="submit"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                <i class="fas fa-lock mr-2"></i>
                                <?php echo $manager_pin_configured ? 'Change manager PIN' : 'Save manager PIN'; ?>
                            </button>
                        </div>
                    </form>
                </section>

                <!-- CSV Import Section -->
                <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6 flex flex-col">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center shrink-0">
                            <i class="fas fa-file-csv text-teal-600"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">Import Products from CSV</h2>
                            <p class="text-xs text-gray-500">Bulk import or update inventory</p>
                        </div>
                    </div>
                   
                    <form action="" method="POST" enctype="multipart/form-data" class="space-y-4 flex-1 flex flex-col">
                        <div>
                            <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">Select CSV File</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv"
                                   class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100"
                                   required>
                        </div>
                        <div class="mt-auto pt-3 flex justify-end">
                            <button type="submit" name="import_csv" value="1"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700">
                                <i class="fas fa-upload mr-2"></i>
                                Import Products
                            </button>
                        </div>
                    </form>
                </section>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function addCustomPort() {
            const customPortInput = document.getElementById('custom_port');
            const customPort = customPortInput.value.trim();
            
            if (customPort === '') {
                alert('Please enter a custom port name.');
                return;
            }
            
            const portSelect = document.getElementById('printer_port');
            
            // Check if the port already exists
            for (let i = 0; i < portSelect.options.length; i++) {
                if (portSelect.options[i].value === customPort) {
                    portSelect.value = customPort;
                    customPortInput.value = '';
                    return;
                }
            }
            
            // Add new option
            const newOption = document.createElement('option');
            newOption.value = customPort;
            newOption.text = customPort;
            portSelect.add(newOption);
            
            // Select the new option
            portSelect.value = customPort;
            customPortInput.value = '';
        }
        
        // Check if Android Printer interface is available
        function checkAndroidPrinter() {
            const androidPrinterSection = document.getElementById('androidPrinterSettings');
            const statusElement = document.getElementById('androidPrinterStatus');
            
            if (!androidPrinterSection) return;
            
            if (window.AndroidPrinter && typeof window.AndroidPrinter.openSettings === 'function') {
                // Android app detected
                if (statusElement) {
                    statusElement.textContent = '✓ Android app detected - Click to configure printer';
                    statusElement.classList.remove('hidden');
                    statusElement.classList.remove('text-red-600');
                    statusElement.classList.add('text-green-600');
                }
            } else {
                // Not in Android app
                if (statusElement) {
                    statusElement.textContent = 'ℹ️ Available only in Android app - Install the app to configure printers';
                    statusElement.classList.remove('hidden');
                    statusElement.classList.remove('text-green-600');
                    statusElement.classList.add('text-blue-600');
                }
            }
        }
        
        // Open Android Printer Settings
        function openAndroidPrinterSettings() {
            if (window.AndroidPrinter && typeof window.AndroidPrinter.openSettings === 'function') {
                try {
                    window.AndroidPrinter.openSettings();
                    showToast('success', 'Opening printer settings...');
                } catch (error) {
                    console.error('Error opening printer settings:', error);
                    showToast('error', 'Failed to open printer settings. Please try again.');
                }
            } else {
                showToast('info', 'Printer settings are only available in the Android app. Please open this page in the Android app to configure printers.');
            }
        }
        
        // Check on page load
        document.addEventListener('DOMContentLoaded', function() {
            checkAndroidPrinter();
            
            // Also check after a short delay in case AndroidPrinter loads asynchronously
            setTimeout(checkAndroidPrinter, 500);
            setTimeout(checkAndroidPrinter, 1500);
        });
        
        // Toast handling
        function showToast(type, message) {
            const toast = document.getElementById('toast');
            const icon = toast.querySelector('.toast-icon');
            const msgElement = toast.querySelector('.toast-message');
            
            // Set styling based on type
            toast.classList.remove('border-red-500', 'border-teal-500', 'border-blue-500', 'bg-red-50', 'bg-teal-50', 'bg-blue-50');
            let borderClass, bgClass;
            if (type === 'success') {
                borderClass = 'border-teal-500';
                bgClass = 'bg-teal-50';
            } else if (type === 'info') {
                borderClass = 'border-blue-500';
                bgClass = 'bg-blue-50';
            } else {
                borderClass = 'border-red-500';
                bgClass = 'bg-red-50';
            }
            toast.classList.add(borderClass, bgClass);
            
            msgElement.textContent = message;
            toast.classList.remove('translate-x-full');
            toast.classList.remove('hidden');
            
            // Auto-hide after 4 seconds
            setTimeout(hideToast, 4000);
        }

        function hideToast() {
            const toast = document.getElementById('toast');
            toast.classList.add('translate-x-full');
            setTimeout(() => toast.classList.add('hidden'), 300);
        }
    </script>
</body>
</html> 