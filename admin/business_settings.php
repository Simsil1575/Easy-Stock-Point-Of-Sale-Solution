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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    
    // Get current receipt setting
    $defaultPrintReceipt = 0;
    try {
        $posDb = new PDO('sqlite:../pos.db');
        $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
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
        
        // Get current setting
        $receiptSetting = $posDb->query("SELECT default_print_receipt FROM product_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $defaultPrintReceipt = $receiptSetting ? ($receiptSetting['default_print_receipt'] ?? 0) : 0;
    } catch(PDOException $e) {
        $defaultPrintReceipt = 0;
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
                        Go Back
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
                    <form method="post" action="">
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
                                       value="<?php echo htmlspecialchars($businessInfo['phone']); ?>" required>
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
                                            <div class="flex-1">
                                                <h4 class="text-sm font-semibold text-blue-900 mb-1">📱 Android Printer Settings</h4>
                                                <p class="text-xs text-blue-700">Configure Bluetooth, USB, or Network printers for Android devices</p>
                                                <p id="androidPrinterStatus" class="text-xs text-blue-600 mt-1 hidden"></p>
                                            </div>
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
                                                <strong>Important:</strong> Changing the VAT setting or VAT rate will automatically convert all product prices in your inventory. 
                                                Make sure to backup your data before making changes.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div class="col-span-2 sm:col-span-1">
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
                                    
                                    <div class="col-span-2 sm:col-span-1">
                                        <label for="vat_rate" class="block text-sm font-medium text-gray-700">VAT Rate (%)</label>
                                        <input type="number" id="vat_rate" name="vat_rate" step="0.01" min="0" max="100" 
                                               class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                               value="<?php echo htmlspecialchars(number_format($vatRate, 2, '.', '')); ?>" required>
                                        <p class="mt-1 text-xs text-gray-500">Enter the VAT rate percentage (e.g., 15 for 15%). Default is 15% for Namibia.</p>
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