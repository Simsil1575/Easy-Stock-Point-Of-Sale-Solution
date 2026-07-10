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
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_receipt_setting']) && !isset($_POST['import_csv']) && !isset($_POST['update_cashier_permissions']) && !isset($_POST['update_cashier_inactivity']) && !isset($_POST['update_waitress_permissions']) && !isset($_POST['update_pos_interface'])) {
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
        } catch(PDOException $e) {
            $errorMessage = 'Error updating receipt settings: ' . $e->getMessage();
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
                allow_settings BOOLEAN NOT NULL DEFAULT 0
            )");
            
            // Check if row exists
            $checkStmt = $db->query("SELECT COUNT(*) FROM cashier_permissions")->fetchColumn();
            if ($checkStmt == 0) {
                $db->exec("INSERT INTO cashier_permissions (allow_tabs, allow_transactions, allow_credit_book, allow_cash_inout, allow_settings) VALUES (1, 1, 1, 1, 0)");
            }
            
            // Update permissions
            $allowTabs = isset($_POST['allow_tabs']) ? 1 : 0;
            $allowTransactions = isset($_POST['allow_transactions']) ? 1 : 0;
            $allowCreditBook = isset($_POST['allow_credit_book']) ? 1 : 0;
            $allowCashInOut = isset($_POST['allow_cash_inout']) ? 1 : 0;
            $allowSettings = isset($_POST['allow_settings']) ? 1 : 0;
            
            $updateStmt = $db->prepare("UPDATE cashier_permissions SET 
                allow_tabs = :allow_tabs,
                allow_transactions = :allow_transactions,
                allow_credit_book = :allow_credit_book,
                allow_cash_inout = :allow_cash_inout,
                allow_settings = :allow_settings
                WHERE id = 1");
            $updateStmt->execute([
                ':allow_tabs' => $allowTabs,
                ':allow_transactions' => $allowTransactions,
                ':allow_credit_book' => $allowCreditBook,
                ':allow_cash_inout' => $allowCashInOut,
                ':allow_settings' => $allowSettings
            ]);
            
            $successMessage = 'Cashier permissions updated successfully!';
        } catch(PDOException $e) {
            $errorMessage = 'Error updating cashier permissions: ' . $e->getMessage();
        }
    }
    
    // Get current cashier permissions
    $cashierPermissions = [
        'allow_tabs' => 1,
        'allow_transactions' => 1,
        'allow_credit_book' => 1,
        'allow_cash_inout' => 1,
        'allow_settings' => 0
    ];
    try {
        // Create table if it doesn't exist
        $db->exec("CREATE TABLE IF NOT EXISTS cashier_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            allow_tabs BOOLEAN NOT NULL DEFAULT 1,
            allow_transactions BOOLEAN NOT NULL DEFAULT 1,
            allow_credit_book BOOLEAN NOT NULL DEFAULT 1,
            allow_cash_inout BOOLEAN NOT NULL DEFAULT 1,
            allow_settings BOOLEAN NOT NULL DEFAULT 0
        )");
        
        // Get current permissions
        $permissions = $db->query("SELECT * FROM cashier_permissions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($permissions) {
            $cashierPermissions = [
                'allow_tabs' => $permissions['allow_tabs'] ?? 1,
                'allow_transactions' => $permissions['allow_transactions'] ?? 1,
                'allow_credit_book' => $permissions['allow_credit_book'] ?? 1,
                'allow_cash_inout' => $permissions['allow_cash_inout'] ?? 1,
                'allow_settings' => $permissions['allow_settings'] ?? 0
            ];
        } else {
            // Insert default row
            $db->exec("INSERT INTO cashier_permissions (allow_tabs, allow_transactions, allow_credit_book, allow_cash_inout, allow_settings) VALUES (1, 1, 1, 1, 0)");
        }
    } catch(PDOException $e) {
        // Use defaults if error
    }
    
    // Get current receipt and cashier inactivity settings
    $defaultPrintReceipt = 0;
    $cashierInactivityEnabled = 1;
    $cashierIdleTimeoutSeconds = 120;
    $touchKeyboardEnabled = 0;
    try {
        require_once __DIR__ . '/../touch_keyboard_settings_helper.php';
        $posDb = new PDO('sqlite:../pos.db');
        $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ensureTouchKeyboardSettingsColumn($posDb);
        
        // Check if default_print_receipt column exists, if not add it
        $columns = $posDb->query("PRAGMA table_info(product_settings)")->fetchAll(PDO::FETCH_ASSOC);
        $columnExists = false;
        foreach($columns as $column) {
            if($column['name'] === 'default_print_receipt') {
                $columnExists = true;
                break;
            }
        }
        
        if(!$columnExists) {
            $posDb->exec("ALTER TABLE product_settings ADD COLUMN default_print_receipt BOOLEAN NOT NULL DEFAULT 0");
        }
        try {
            $posDb->exec("ALTER TABLE product_settings ADD COLUMN cashier_idle_timeout_seconds INTEGER NOT NULL DEFAULT 120");
        } catch (PDOException $e) {
        }
        try {
            $posDb->exec("ALTER TABLE product_settings ADD COLUMN cashier_inactivity_enabled BOOLEAN NOT NULL DEFAULT 1");
        } catch (PDOException $e) {
        }
        try {
            $posDb->exec("ALTER TABLE product_settings ADD COLUMN drawer_open_on_checkout TEXT NOT NULL DEFAULT 'on_ok'");
        } catch (PDOException $e) {
        }
        try {
            $posDb->exec("ALTER TABLE product_settings ADD COLUMN show_reverse_transaction BOOLEAN NOT NULL DEFAULT 1");
        } catch (PDOException $e) {
        }
        try {
            $posDb->exec("ALTER TABLE product_settings ADD COLUMN waitress_can_take_tab_payments BOOLEAN NOT NULL DEFAULT 0");
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
        
        // Get current setting
        $receiptSetting = $posDb->query("SELECT default_print_receipt, cashier_inactivity_enabled, cashier_idle_timeout_seconds, drawer_open_on_checkout, show_reverse_transaction, waitress_can_take_tab_payments, touch_keyboard_enabled, inactivity_role_admin, inactivity_role_manager, inactivity_role_cashier, inactivity_role_waitress FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $defaultPrintReceipt = $receiptSetting ? ($receiptSetting['default_print_receipt'] ?? 0) : 0;
        $cashierInactivityEnabled = $receiptSetting ? (int) ($receiptSetting['cashier_inactivity_enabled'] ?? 1) : 1;
        $cashierIdleTimeoutSeconds = $receiptSetting ? (int) ($receiptSetting['cashier_idle_timeout_seconds'] ?? 120) : 120;
        $inactivityRoleAdmin = $receiptSetting ? (int) ($receiptSetting['inactivity_role_admin'] ?? 0) : 0;
        $inactivityRoleManager = $receiptSetting ? (int) ($receiptSetting['inactivity_role_manager'] ?? 0) : 0;
        $inactivityRoleCashier = $receiptSetting ? (int) ($receiptSetting['inactivity_role_cashier'] ?? 1) : 1;
        $inactivityRoleWaitress = $receiptSetting ? (int) ($receiptSetting['inactivity_role_waitress'] ?? 0) : 0;
        $drawerOpenOnCheckout = $receiptSetting ? ($receiptSetting['drawer_open_on_checkout'] ?? 'on_ok') : 'on_ok';
        $showReverseTransaction = $receiptSetting ? (int) ($receiptSetting['show_reverse_transaction'] ?? 1) : 1;
        $waitressCanTakeTabPayments = $receiptSetting ? (int) ($receiptSetting['waitress_can_take_tab_payments'] ?? 0) : 0;
        $touchKeyboardEnabled = $receiptSetting ? (int) ($receiptSetting['touch_keyboard_enabled'] ?? 0) : 0;
        if ($cashierIdleTimeoutSeconds < 30) {
            $cashierIdleTimeoutSeconds = 30;
        }
        if ($cashierIdleTimeoutSeconds > 3600) {
            $cashierIdleTimeoutSeconds = 3600;
        }
    } catch(PDOException $e) {
        $defaultPrintReceipt = 0;
        $cashierInactivityEnabled = 1;
        $cashierIdleTimeoutSeconds = 120;
        $inactivityRoleAdmin = 0;
        $inactivityRoleManager = 0;
        $inactivityRoleCashier = 1;
        $inactivityRoleWaitress = 0;
        $waitressCanTakeTabPayments = 0;
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
    <title>Business Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio,line-clamp"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="3.4.16"></script>
</head>
<body class="bg-gray-50">
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
        <div class="flex-1 ml-64 content">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex justify-between items-center mb-8">
                    <h1 class="text-3xl font-bold">Business Settings</h1>
                    
                    <a href="settings" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Settings
                    </a>
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
                
                <!-- Business Settings Form -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden p-6">
                    <form method="post" action="" enctype="multipart/form-data">
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div class="col-span-2 sm:col-span-1">
                                <label for="name" class="block text-sm font-medium text-gray-700">Business Name</label>
                                <input type="text" id="name" name="name" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                       value="<?php echo htmlspecialchars($businessInfo['name']); ?>" required>
                            </div>
                            
                            <div class="col-span-2 sm:col-span-1">
                                <label for="location" class="block text-sm font-medium text-gray-700">Business Location</label>
                                <input type="text" id="location" name="location" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                       value="<?php echo htmlspecialchars($businessInfo['location']); ?>" required>
                            </div>
                            
                            <div class="col-span-2 sm:col-span-1">
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                <input type="text" id="phone" name="phone" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                       value="<?php echo htmlspecialchars($businessInfo['phone']); ?>">
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
                            <div class="col-span-2">
                                <label for="business_logo" class="block text-sm font-medium text-gray-700">Receipt Business Logo</label>
                                <p class="mt-1 text-xs text-gray-500 mb-3">Upload a logo to print at the top of every receipt (sales, reports, payments, etc.). Recommended: square or wide PNG/JPG, max 2 MB.</p>
                                <?php if ($logoPreviewUrl !== ''): ?>
                                    <div class="mb-4 flex flex-col sm:flex-row sm:items-center gap-4 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                        <img src="<?php echo htmlspecialchars($logoPreviewUrl); ?>" alt="Current business logo" class="h-20 w-auto max-w-[200px] object-contain bg-white border border-gray-200 rounded p-2">
                                        <label class="inline-flex items-center text-sm text-gray-700">
                                            <input type="checkbox" name="remove_logo" value="1" class="h-4 w-4 text-red-600 border-gray-300 rounded focus:ring-red-500 mr-2">
                                            Remove current logo
                                        </label>
                                    </div>
                                <?php endif; ?>
                                <input type="file" id="business_logo" name="business_logo" accept="image/jpeg,image/png,image/gif,image/webp"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
                            </div>

                            <div class="col-span-2 sm:col-span-1">
                                <label for="header_custom_text" class="block text-sm font-medium text-gray-700">Receipt Header Custom Text</label>
                                <input type="text" id="header_custom_text" name="header_custom_text" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars($businessInfo['header_custom_text'] ?? ''); ?>" placeholder="Optional text shown under business name">
                            </div>
                            
                            <div class="col-span-2 sm:col-span-1">
                                <label for="closing_time" class="block text-sm font-medium text-gray-700">Business Closing Time</label>
                                <input type="time" id="closing_time" name="closing_time" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                       value="<?php echo htmlspecialchars($closingTime); ?>">
                                <p class="mt-1 text-xs text-gray-500">Set the time when your business closes each day. System will alert for transactions after this time.</p>
                            </div>
                            
                            <div class="col-span-2 sm:col-span-1">
                                <label for="printer_port" class="block text-sm font-medium text-gray-700">Printer Port</label>
                                <select id="printer_port" name="printer_port" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                                    <?php
                                    // Common printer ports
                                    $ports = ['COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'LPT1', 'USB001', '/dev/usb/lp0'];
                                    
                                    // Add custom port if it's not in the list
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
                                        <button type="button" class="inline-flex items-center px-3 py-2 border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-sm rounded-r-md hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500" 
                                                onclick="addCustomPort()">Add</button>
                                    </div>
                                </div>
                                
                                <!-- Android Printer Settings Button -->
                                <div id="androidPrinterSettings" class="mt-4">
                                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                         
                                            <button type="button" 
                                                    onclick="openAndroidPrinterSettings()"
                                                    class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150 ease-in-out">
                                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                </svg>
                                                Open Printer Settings
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-span-2">
                                <label for="footer_text" class="block text-sm font-medium text-gray-700">Receipt Footer Text</label>
                                <textarea id="footer_text" name="footer_text" rows="3" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($businessInfo['footer_text']); ?></textarea>
                            </div>
                            
                            <!-- VAT Settings Section -->
                            <div class="col-span-2 border-t pt-6 mt-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Namibian VAT Settings</h3>
                                
                                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4 rounded-md">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-yellow-700">
                                                <strong>Important:</strong> If you change the VAT mode or the VAT rate (while on VAT included), you can optionally recalculate all product prices below. Back up your data before using that option.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div class="col-span-2 sm:col-span-1">
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
                                    
                                    <div class="col-span-2 sm:col-span-1">
                                        <label for="vat_rate" class="block text-sm font-medium text-gray-700">VAT Rate (%)</label>
                                        <input type="number" id="vat_rate" name="vat_rate" step="0.01" min="0" max="100" 
                                               class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                               value="<?php echo htmlspecialchars(number_format($vatRate, 2, '.', '')); ?>" required>
                                        <p class="mt-1 text-xs text-gray-500">Enter the VAT rate percentage (e.g., 15 for 15%). Default is 15% for Namibia.</p>
                                    </div>
                                    
                                    <div class="col-span-2 flex items-start p-4 bg-gray-50 border border-gray-200 rounded-md">
                                        <div class="flex items-center h-5">
                                            <input id="convert_inventory_prices" name="convert_inventory_prices" type="checkbox" value="1"
                                                   class="h-4 w-4 text-teal-600 border-gray-300 rounded focus:ring-teal-500">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="convert_inventory_prices" class="font-medium text-gray-800">Adjust inventory prices to match this save</label>
                                            <p class="text-gray-500 mt-1">When ticked, all product prices in the POS are recalculated when you change the VAT mode, or when you change the VAT rate while <span class="whitespace-nowrap">VAT included</span> is selected. Leave unticked to only update VAT/receipt settings and keep your current prices.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end">
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Cashier Permissions Section -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden p-6 mt-8">
                    <h2 class="text-2xl font-bold mb-6">Cashier Sidebar Permissions</h2>
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-blue-700">
                                    <strong>Note:</strong> Control which sidebar menu options are available to cashiers. Admins always have access to all options. Home and Logout are always visible to all users.
                                </p>
                            </div>
                        </div>
                    </div>
                    <form action="" method="POST" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4">
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <label for="allow_tabs" class="block text-sm font-medium text-gray-700 mb-1">
                                        Allow Tabs
                                    </label>
                                    <p class="text-xs text-gray-500">Enable cashiers to access the Tabs menu</p>
                                </div>
                                <div class="ml-4">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="allow_tabs" id="allow_tabs" 
                                               class="sr-only peer" 
                                               <?php echo $cashierPermissions['allow_tabs'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <label for="allow_transactions" class="block text-sm font-medium text-gray-700 mb-1">
                                        Allow Transactions
                                    </label>
                                    <p class="text-xs text-gray-500">Enable cashiers to access the Transactions/Reports menu</p>
                                </div>
                                <div class="ml-4">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="allow_transactions" id="allow_transactions" 
                                               class="sr-only peer" 
                                               <?php echo $cashierPermissions['allow_transactions'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <label for="allow_credit_book" class="block text-sm font-medium text-gray-700 mb-1">
                                        Allow Credit Book
                                    </label>
                                    <p class="text-xs text-gray-500">Enable cashiers to access the Credit Book menu</p>
                                </div>
                                <div class="ml-4">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="allow_credit_book" id="allow_credit_book" 
                                               class="sr-only peer" 
                                               <?php echo $cashierPermissions['allow_credit_book'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <label for="allow_cash_inout" class="block text-sm font-medium text-gray-700 mb-1">
                                        Allow Cash In/Out
                                    </label>
                                    <p class="text-xs text-gray-500">Enable cashiers to access the Cash In/Out menu</p>
                                </div>
                                <div class="ml-4">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="allow_cash_inout" id="allow_cash_inout" 
                                               class="sr-only peer" 
                                               <?php echo $cashierPermissions['allow_cash_inout'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <label for="allow_settings" class="block text-sm font-medium text-gray-700 mb-1">
                                        Allow Settings
                                    </label>
                                    <p class="text-xs text-gray-500">Enable cashiers to access the Settings menu (not recommended for security)</p>
                                </div>
                                <div class="ml-4">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="allow_settings" id="allow_settings" 
                                               class="sr-only peer" 
                                               <?php echo $cashierPermissions['allow_settings'] ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end mt-6">
                            <button type="submit" 
                                    name="update_cashier_permissions" 
                                    value="1"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Save Permissions
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Inactivity Logout Section -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden p-6 mt-8">
                    <h2 class="text-2xl font-bold mb-6">Inactivity Logout</h2>
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-blue-700">
                                    When enabled, selected user roles are logged out automatically after a period of inactivity (when the cart is empty on POS). Choose which roles this applies to below.
                                </p>
                            </div>
                        </div>
                    </div>
                    <form action="" method="POST" class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <label for="cashier_inactivity_enabled" class="block text-sm font-medium text-gray-700 mb-1">
                                    Enable inactivity logout
                                </label>
                                <p class="text-xs text-gray-500">Master switch for automatic logout by role.</p>
                            </div>
                            <div class="ml-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="cashier_inactivity_enabled" id="cashier_inactivity_enabled"
                                           class="sr-only peer"
                                           <?php echo $cashierInactivityEnabled ? 'checked' : ''; ?>>
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                </label>
                            </div>
                        </div>
                        <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <p class="block text-sm font-medium text-gray-700 mb-3">Apply inactivity timer to</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="inactivity_role_admin" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" <?php echo $inactivityRoleAdmin ? 'checked' : ''; ?>>
                                    Admin
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="inactivity_role_manager" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" <?php echo $inactivityRoleManager ? 'checked' : ''; ?>>
                                    Manager
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="inactivity_role_cashier" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" <?php echo $inactivityRoleCashier ? 'checked' : ''; ?>>
                                    Cashier
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="inactivity_role_waitress" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" <?php echo $inactivityRoleWaitress ? 'checked' : ''; ?>>
                                    Waitress
                                </label>
                            </div>
                        </div>
                        <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <label for="cashier_idle_timeout_seconds" class="block text-sm font-medium text-gray-700 mb-1">Idle timeout (seconds)</label>
                            <input type="number" id="cashier_idle_timeout_seconds" name="cashier_idle_timeout_seconds"
                                   value="<?php echo (int) $cashierIdleTimeoutSeconds; ?>" min="30" max="3600" step="1"
                                   class="mt-1 block w-40 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                            <p class="mt-2 text-xs text-gray-500">Used only when inactivity logout is enabled. Allowed range: 30–3600 seconds. Default is 120.</p>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit"
                                    name="update_cashier_inactivity"
                                    value="1"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Save Inactivity Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Waitress Permissions Section -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden p-6 mt-8">
                    <h2 class="text-2xl font-bold mb-6">Waitress Permissions</h2>
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6 rounded-md">
                        <p class="text-sm text-blue-700">
                            Control what waitresses can do on the waitress <strong>View Tab</strong> screen. Tab payments are disabled by default; enable only if waitresses should collect cash or EFT on open tabs.
                        </p>
                    </div>
                    <form action="" method="POST" class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <label for="waitress_can_take_tab_payments" class="block text-sm font-medium text-gray-700 mb-1">
                                    Allow waitress tab payments
                                </label>
                                <p class="text-xs text-gray-500">When enabled, waitresses see the Pay button and can record cash, EFT, or mixed payments on open tabs.</p>
                            </div>
                            <div class="ml-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="waitress_can_take_tab_payments" id="waitress_can_take_tab_payments"
                                           class="sr-only peer"
                                           <?php echo $waitressCanTakeTabPayments ? 'checked' : ''; ?>>
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                </label>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit"
                                    name="update_waitress_permissions"
                                    value="1"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                Save Waitress Permissions
                            </button>
                        </div>
                    </form>
                </div>

                <!-- POS Interface Section -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden p-6 mt-8">
                    <h2 class="text-2xl font-bold mb-6">POS Interface</h2>
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6 rounded-md">
                        <p class="text-sm text-blue-700">
                            Control on-screen touch keyboard behavior on desktop and tablet POS screens. When disabled, staff use the physical keyboard only.
                        </p>
                    </div>
                    <form action="" method="POST" class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <label for="touch_keyboard_enabled" class="block text-sm font-medium text-gray-700 mb-1">
                                    Enable touch keyboard
                                </label>
                                <p class="text-xs text-gray-500">Shows the on-screen keyboard panel for cash, payment, login, and tab fields on desktop/tablet POS screens.</p>
                            </div>
                            <div class="ml-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="touch_keyboard_enabled" id="touch_keyboard_enabled"
                                           class="sr-only peer"
                                           <?php echo $touchKeyboardEnabled ? 'checked' : ''; ?>>
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                </label>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit"
                                    name="update_pos_interface"
                                    value="1"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                Save POS Interface Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Receipt Settings Section -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden p-6 mt-8">
                    <h2 class="text-2xl font-bold mb-6">Receipt Settings</h2>
                    <form action="" method="POST" class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <label for="default_print_receipt" class="block text-sm font-medium text-gray-700 mb-1">
                                    Default "Print with Receipt" Checkbox
                                </label>
                                <p class="text-xs text-gray-500">When enabled, the "Print with receipt" checkbox will be checked by default during checkout.</p>
                            </div>
                            <div class="ml-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="default_print_receipt" id="default_print_receipt" 
                                           class="sr-only peer" 
                                           <?php echo $defaultPrintReceipt ? 'checked' : ''; ?>
                                           onchange="this.form.submit()">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gray-600"></div>
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <label for="drawer_open_on_checkout" class="block text-sm font-medium text-gray-700 mb-1">
                                    Cash Drawer Open On
                                </label>
                                <p class="text-xs text-gray-500">Control when the cash drawer opens for cash transactions: immediately on checkout, or when you press OK to confirm.</p>
                            </div>
                            <div class="ml-4">
                                <select name="drawer_open_on_checkout" id="drawer_open_on_checkout" 
                                        class="px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-teal-500 focus:border-teal-500 text-sm"
                                        onchange="this.form.submit()">
                                    <option value="on_ok" <?php echo ($drawerOpenOnCheckout === 'on_ok') ? 'selected' : ''; ?>>On OK (Default)</option>
                                    <option value="on_checkout" <?php echo ($drawerOpenOnCheckout === 'on_checkout') ? 'selected' : ''; ?>>On Checkout</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <label for="show_reverse_transaction" class="block text-sm font-medium text-gray-700 mb-1">
                                    Show "Reverse Transaction" Option
                                </label>
                                <p class="text-xs text-gray-500">When enabled, users can reverse the last transaction from the payment confirmation screen.</p>
                            </div>
                            <div class="ml-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="show_reverse_transaction" id="show_reverse_transaction" 
                                           class="sr-only peer" 
                                           <?php echo $showReverseTransaction ? 'checked' : ''; ?>
                                           onchange="this.form.submit()">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                                </label>
                            </div>
                        </div>
                        <input type="hidden" name="update_receipt_setting" value="1">
                    </form>
                </div>
                
                <!-- CSV Import Section -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden p-6 mt-8">
                    <h2 class="text-2xl font-bold mb-6">Import Products from CSV</h2>
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-blue-700">
                                    <strong>CSV Format:</strong> The CSV file should contain columns: STOCKCODE, BARCODE, DESCRIPTION, PRICE, PRICEINC (inclusive), PRICE2, PRICEINC2, PRICE3, PRICEINC3, UNITCOST, DEPARTMENT, CATEGORY, STOCKONHAND, SUPPLIER
                                    <br><strong>Note:</strong> Only the PRICEINC column (inclusive) will be used. Products will be matched by name (DESCRIPTION). Existing products will be updated, new products will be imported.
                                </p>
                            </div>
                        </div>
                    </div>
                    <form action="" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">
                                    Select CSV File
                                </label>
                                <input type="file" 
                                       id="csv_file" 
                                       name="csv_file" 
                                       accept=".csv"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100"
                                       required>
                                <p class="mt-2 text-xs text-gray-500">Select a CSV file to import products. The file should match the PLF.csv format.</p>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" 
                                    name="import_csv" 
                                    value="1"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                                Import Products
                            </button>
                        </div>
                    </form>
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