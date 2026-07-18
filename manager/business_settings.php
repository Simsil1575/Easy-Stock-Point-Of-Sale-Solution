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
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_receipt_setting']) && !isset($_POST['import_csv']) && !isset($_POST['update_cashier_permissions']) && !isset($_POST['update_cashier_inactivity']) && !isset($_POST['update_pos_interface'])) {
        // Validate inputs
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $footer_text = trim($_POST['footer_text'] ?? '');
        $printer_port = trim($_POST['printer_port'] ?? 'COM4');
        $closing_time = trim($_POST['closing_time'] ?? '22:00');
        $vat_inclusive = trim($_POST['vat_inclusive'] ?? 'exclusive');
        $vat_rate = floatval($_POST['vat_rate'] ?? 15.0);
        
        // Validate VAT rate (should be between 0 and 100)
        if ($vat_rate < 0 || $vat_rate > 100) {
            $errorMessage = 'VAT rate must be between 0 and 100.';
        } elseif (empty($name) || empty($location) || empty($phone)) {
            $errorMessage = 'Business name, location, and phone are required fields.';
        } else {
            // Get current VAT settings before updating
            $currentBusinessInfo = $db->query("SELECT vat_inclusive, vat_rate FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $oldVatInclusive = $currentBusinessInfo ? ($currentBusinessInfo['vat_inclusive'] ?? 'exclusive') : 'exclusive';
            $oldVatRate = $currentBusinessInfo ? (floatval($currentBusinessInfo['vat_rate'] ?? 15.0)) : 15.0;
            
            // Check if a record exists
            $check = $db->query("SELECT COUNT(*) FROM business_info")->fetchColumn();
            
            if ($check > 0) {
                // Update existing record
                $stmt = $db->prepare("UPDATE business_info SET 
                    name = :name, 
                    location = :location, 
                    phone = :phone, 
                    footer_text = :footer_text,
                    printer_port = :printer_port,
                    closing_time = :closing_time,
                    vat_inclusive = :vat_inclusive,
                    vat_rate = :vat_rate
                    WHERE id = 1");
            } else {
                // Insert new record
                $stmt = $db->prepare("INSERT INTO business_info 
                    (name, location, phone, footer_text, printer_port, closing_time, vat_inclusive, vat_rate) 
                    VALUES (:name, :location, :phone, :footer_text, :printer_port, :closing_time, :vat_inclusive, :vat_rate)");
            }
            
            $stmt->execute([
                ':name' => $name,
                ':location' => $location,
                ':phone' => $phone,
                ':footer_text' => $footer_text,
                ':printer_port' => $printer_port,
                ':closing_time' => $closing_time,
                ':vat_inclusive' => $vat_inclusive,
                ':vat_rate' => $vat_rate
            ]);
            
            // Convert product prices if VAT setting changed
            if ($oldVatInclusive !== $vat_inclusive) {
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
                        
                        // Convert based on direction of change
                        if ($oldVatInclusive === 'exclusive' && $vat_inclusive === 'inclusive') {
                            // Converting from exclusive to inclusive: add VAT
                            $newPrice = $oldPrice * (1 + ($vat_rate / 100));
                        } elseif ($oldVatInclusive === 'inclusive' && $vat_inclusive === 'exclusive') {
                            // Converting from inclusive to exclusive: remove VAT
                            $newPrice = $oldPrice / (1 + ($vat_rate / 100));
                        }
                        
                        // Round to 2 decimal places
                        $newPrice = round($newPrice, 2);
                        
                        // Update product price
                        $updateStmt->execute([
                            ':price' => $newPrice,
                            ':id' => $product['id']
                        ]);
                        $convertedCount++;
                    }
                    
                    $successMessage = 'Business information updated successfully! ' . $convertedCount . ' product price(s) converted from ' . 
                                    ($oldVatInclusive === 'exclusive' ? 'VAT exclusive' : 'VAT inclusive') . ' to ' . 
                                    ($vat_inclusive === 'exclusive' ? 'VAT exclusive' : 'VAT inclusive') . '.';
                } catch (PDOException $e) {
                    $successMessage = 'Business information updated successfully! However, there was an error converting product prices: ' . $e->getMessage();
                }
            } elseif ($oldVatRate != $vat_rate && $vat_inclusive === 'inclusive') {
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
                $successMessage = 'Business information updated successfully!';
            }
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
            
            // Update setting
            $newValue = isset($_POST['default_print_receipt']) ? 1 : 0;
            $updateStmt = $posDb->prepare("UPDATE product_settings SET default_print_receipt = ? WHERE id = 1");
            $updateStmt->execute([$newValue]);
            
            $successMessage = 'Receipt setting updated successfully!';
        } catch(PDOException $e) {
            $errorMessage = 'Error updating receipt setting: ' . $e->getMessage();
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

    // Handle inactivity settings form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cashier_inactivity'])) {
        try {
            require_once __DIR__ . '/../inactivity_settings_helper.php';
            $posDb = new PDO('sqlite:../pos.db');
            $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $posDb->exec("CREATE TABLE IF NOT EXISTS product_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                show_all_products BOOLEAN NOT NULL DEFAULT 0,
                default_print_receipt BOOLEAN NOT NULL DEFAULT 0
            )");
            ensureInactivitySettingsColumns($posDb);

            $checkStmt = $posDb->query("SELECT COUNT(*) FROM product_settings")->fetchColumn();
            if ($checkStmt == 0) {
                $posDb->exec("INSERT INTO product_settings (show_all_products, default_print_receipt, cashier_inactivity_enabled, cashier_idle_timeout_seconds, inactivity_role_cashier) VALUES (0, 0, 1, 120, 1)");
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

            $successMessage = 'Inactivity logout settings updated successfully!';
        } catch (PDOException $e) {
            $errorMessage = 'Error updating inactivity settings: ' . $e->getMessage();
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
                    <p class="text-sm text-gray-500 mt-1">Name, branding, VAT, and product CSV import</p>
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
                <form method="post" action="" class="contents">
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
                                       value="<?php echo htmlspecialchars($businessInfo['phone']); ?>" required>
                            </div>
                            <div>
                                <label for="closing_time" class="block text-sm font-medium text-gray-700">Business Closing Time</label>
                                <input type="time" id="closing_time" name="closing_time" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                       value="<?php echo htmlspecialchars($closingTime); ?>">
                                <p class="mt-1 text-xs text-gray-500">System alerts for transactions after this time.</p>
                            </div>
                        </div>
                    </section>

                    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center shrink-0">
                                <i class="fas fa-receipt text-blue-600"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Printer &amp; receipt branding</h2>
                                <p class="text-xs text-gray-500">Footer text &amp; printer port</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label for="footer_text" class="block text-sm font-medium text-gray-700">Receipt Footer Text</label>
                                <textarea id="footer_text" name="footer_text" rows="3" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($businessInfo['footer_text']); ?></textarea>
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
                                <div id="androidPrinterSettings" class="mt-3">
                                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                                        <button type="button" onclick="openAndroidPrinterSettings()"
                                                class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                            <i class="fas fa-cog mr-2"></i>
                                            Open Printer Settings
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6 flex flex-col">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center shrink-0">
                                <i class="fas fa-percent text-amber-600"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Namibian VAT Settings</h2>
                                <p class="text-xs text-gray-500">VAT mode &amp; rate</p>
                            </div>
                        </div>
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 mb-4 rounded-md">
                            <p class="text-sm text-yellow-700">
                                <strong>Important:</strong> Changing the VAT setting or VAT rate will automatically convert all product prices in your inventory. Make sure to backup your data before making changes.
                            </p>
                        </div>
                        <div class="space-y-4 flex-1">
                            <div>
                                <label for="vat_inclusive" class="block text-sm font-medium text-gray-700 mb-2">Price Display</label>
                                <select id="vat_inclusive" name="vat_inclusive" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                                    <option value="exclusive" <?php echo ($vatInclusive === 'exclusive') ? 'selected' : ''; ?>>VAT Exclusive (Prices shown exclude VAT)</option>
                                    <option value="inclusive" <?php echo ($vatInclusive === 'inclusive') ? 'selected' : ''; ?>>VAT Inclusive (Prices shown include VAT)</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">
                                    <?php if ($vatInclusive === 'exclusive'): ?>
                                        Prices displayed will exclude VAT. VAT will be added at checkout.
                                    <?php else: ?>
                                        Prices displayed will include VAT. No additional VAT will be added at checkout.
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
                        </div>
                        <div class="mt-5 flex justify-end">
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700">
                                <i class="fas fa-check mr-2"></i>
                                Save Settings
                            </button>
                        </div>
                    </section>
                </form>

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
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-3 mb-4 rounded-md">
                        <p class="text-sm text-blue-700">
                            <strong>CSV Format:</strong> STOCKCODE, BARCODE, DESCRIPTION, PRICE, PRICEINC (inclusive), ...
                            <br><strong>Note:</strong> Only PRICEINC is used. Products matched by DESCRIPTION.
                        </p>
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