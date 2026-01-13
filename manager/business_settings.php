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
        closing_time TEXT NOT NULL DEFAULT '22:00'
    )");
    
    // Check if the closing_time column exists, if not add it
    $columns = $db->query("PRAGMA table_info(business_info)")->fetchAll(PDO::FETCH_ASSOC);
    $columnExists = false;
    foreach($columns as $column) {
        if($column['name'] === 'closing_time') {
            $columnExists = true;
            break;
        }
    }
    
    if(!$columnExists) {
        $db->exec("ALTER TABLE business_info ADD COLUMN closing_time TEXT NOT NULL DEFAULT '22:00'");
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
        
        if (empty($name) || empty($location) || empty($phone)) {
            $errorMessage = 'Business name, location, and phone are required fields.';
        } else {
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
                    closing_time = :closing_time
                    WHERE id = 1");
            } else {
                // Insert new record
                $stmt = $db->prepare("INSERT INTO business_info 
                    (name, location, phone, footer_text, printer_port, closing_time) 
                    VALUES (:name, :location, :phone, :footer_text, :printer_port, :closing_time)");
            }
            
            $stmt->execute([
                ':name' => $name,
                ':location' => $location,
                ':phone' => $phone,
                ':footer_text' => $footer_text,
                ':printer_port' => $printer_port,
                ':closing_time' => $closing_time
            ]);
            
            $successMessage = 'Business information updated successfully!';
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
            'closing_time' => '22:00'
        ];
    } else {
        $printerPort = $businessInfo['printer_port'];
        $closingTime = $businessInfo['closing_time'] ?? '22:00';
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
                            </div>
                            
                            <div class="col-span-2">
                                <label for="footer_text" class="block text-sm font-medium text-gray-700">Receipt Footer Text</label>
                                <textarea id="footer_text" name="footer_text" rows="3" class="mt-1 focus:ring-teal-500 focus:border-teal-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($businessInfo['footer_text']); ?></textarea>
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
        
        // Toast handling
        function showToast(type, message) {
            const toast = document.getElementById('toast');
            const icon = toast.querySelector('.toast-icon');
            const msgElement = toast.querySelector('.toast-message');
            
            // Set styling based on type
            toast.classList.remove('border-red-500', 'border-teal-500', 'bg-red-50', 'bg-teal-50');
            const [borderClass, bgClass] = type === 'success' 
                ? ['border-teal-500', 'bg-teal-50'] 
                : ['border-red-500', 'bg-red-50'];
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