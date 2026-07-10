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

// Include secure activation helper
require_once '../activation_helper.php';
require_once __DIR__ . '/../pos_reset_helper.php';

$settingsSection = isset($_GET['s']) && is_string($_GET['s']) ? preg_replace('/[^a-z]/', '', $_GET['s']) : '';
$settingsSectionAllowed = ['display', 'account', 'activation', 'cashout', 'system'];
if (!in_array($settingsSection, $settingsSectionAllowed, true)) {
    $settingsSection = '';
}
$settingsSectionTitles = [
    'display' => 'POS display & printing',
    'account' => 'Account & links',
    'activation' => 'Software activation',
    'cashout' => 'Month-end cashout',
    'system' => 'System management',
];
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <!-- Font Awesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        .sidebar {
            position: fixed;
            height: 100%;
            z-index: 10000 !important; /* Prevent overlay from overlapping sidebar */
        }
        #sidebar {
            z-index: 10000 !important; /* Ensure sidebar stays above overlay */
        }
        .content {
            margin-left: 250px;
        }
        .fade-in {
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .settings-menu-card {
            transition: all 0.3s ease;
        }
        .settings-menu-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            background: white !important;
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000 !important; /* Highest - always accessible */
        }
        
        .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: rgb(0, 0, 0);
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }
        
        .hamburger span:nth-child(1) {
            top: 0px;
        }
        
        .hamburger span:nth-child(2) {
            top: 10px;
        }
        
        .hamburger span:nth-child(3) {
            top: 20px;
        }
        
        /* Open state - transforms into X */
        .hamburger.open span:nth-child(1) {
            top: 10px;
            transform: rotate(135deg);
        }
        
        .hamburger.open span:nth-child(2) {
            opacity: 0;
            left: -60px;
        }
        
        .hamburger.open span:nth-child(3) {
            top: 10px;
            transform: rotate(-135deg);
        }
        
        /* Mobile sidebar overlay */
        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 80 !important; /* MUST be below sidebar (9999) and hamburger (10000) */
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            .content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden;
            }
            
            .container {
                padding: 1rem;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">


<div class="flex">
        <div class="sidebar">
            <?php include 'sidebar.php'; ?>
        </div>

    <!-- Modal Alert System -->
    <div id="alert-modal" class="fixed inset-0 flex items-center justify-center z-50 hidden">
        <div class="absolute inset-0 bg-black opacity-50"></div>
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4 z-10 transform transition-all">
            <div id="alert-icon" class="mx-auto flex items-center justify-center h-12 w-12 rounded-full mb-4"></div>
            <h3 id="alert-title" class="text-lg leading-6 font-medium text-gray-900 text-center"></h3>
            <div id="alert-message" class="mt-2 text-center"></div>
            <div class="mt-5 flex justify-center">
                <button id="alert-confirm" class="inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 text-base font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 sm:text-sm transition-colors duration-200">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirm-modal" class="fixed inset-0 flex items-center justify-center z-50 hidden">
        <div class="absolute inset-0 bg-black opacity-50"></div>
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4 z-10 transform transition-all">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4">
                <svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 id="confirm-title" class="text-lg leading-6 font-medium text-gray-900 text-center">Confirmation</h3>
            <div id="confirm-message" class="mt-2 text-center"></div>
            <div class="mt-5 flex justify-center space-x-4">
                <button id="confirm-cancel" class="inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 sm:text-sm transition-colors duration-200">
                    Cancel
                </button>
                <button id="confirm-yes" class="inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:text-sm transition-colors duration-200">
                    Yes, proceed
                </button>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            hamburger.classList.toggle('open');
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            hamburger.classList.remove('open');
        }
        
        function showAlert(type, title, message, redirectUrl = null) {
            const modal = document.getElementById('alert-modal');
            const iconDiv = document.getElementById('alert-icon');
            const titleElement = document.getElementById('alert-title');
            const messageElement = document.getElementById('alert-message');
            const confirmButton = document.getElementById('alert-confirm');
            
            // Set content
            titleElement.textContent = title;
            messageElement.innerHTML = message;
            
            // Configure based on type
            if (type === 'success') {
                iconDiv.className = 'mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-teal-100 mb-4';
                iconDiv.innerHTML = '<svg class="h-6 w-6 text-teal-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
                confirmButton.className = 'inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-teal-600 text-base font-medium text-white hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 sm:text-sm transition-colors duration-200';
            } else if (type === 'error') {
                iconDiv.className = 'mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4';
                iconDiv.innerHTML = '<svg class="h-6 w-6 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>';
                confirmButton.className = 'inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:text-sm transition-colors duration-200';
            } else if (type === 'warning') {
                iconDiv.className = 'mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4';
                iconDiv.innerHTML = '<svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>';
                confirmButton.className = 'inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-yellow-600 text-base font-medium text-white hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 sm:text-sm transition-colors duration-200';
            } else if (type === 'info') {
                iconDiv.className = 'mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 mb-4';
                iconDiv.innerHTML = '<svg class="h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                confirmButton.className = 'inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:text-sm transition-colors duration-200';
            }
            
            // Handle redirect
            confirmButton.onclick = function() {
                modal.classList.add('hidden');
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                }
            };
            
            // Show modal
            modal.classList.remove('hidden');
        }

        function showConfirm(title, message, callback) {
            const modal = document.getElementById('confirm-modal');
            const titleElement = document.getElementById('confirm-title');
            const messageElement = document.getElementById('confirm-message');
            const cancelButton = document.getElementById('confirm-cancel');
            const confirmButton = document.getElementById('confirm-yes');
            
            // Set content
            titleElement.textContent = title;
            messageElement.innerHTML = message;
            
            // Handle button clicks
            cancelButton.onclick = function() {
                modal.classList.add('hidden');
            };
            
            confirmButton.onclick = function() {
                modal.classList.add('hidden');
                if (typeof callback === 'function') {
                    callback();
                }
            };
            
            // Show modal
            modal.classList.remove('hidden');
        }
    </script>

 
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <!-- Header Row: Title + Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex flex-wrap items-center justify-between gap-3 -mx-6 px-6 shadow-sm">
                    <div class="flex items-center gap-3 min-w-0">
                        <?php if ($settingsSection !== ''): ?>
                        <a href="settings" class="inline-flex shrink-0 items-center gap-2 px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400" title="Back to settings overview">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Back
                        </a>
                        <?php endif; ?>
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2 shrink-0" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0 truncate">Settings</h1>
                            <?php if ($settingsSection !== '' && isset($settingsSectionTitles[$settingsSection])): ?>
                            <p class="text-sm text-gray-600 truncate"><?php echo htmlspecialchars($settingsSectionTitles[$settingsSection], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php elseif ($settingsSection === ''): ?>
                            <p class="text-sm text-gray-600 hidden sm:block">Choose a category below</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($settingsSection === ''): ?>
                <div class="mb-8">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <a href="settings?s=display" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-slate-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-desktop text-slate-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-slate-100 text-slate-700 px-2 py-1 rounded-full">POS</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-slate-900">Display &amp; printing</h3>
                                <p class="text-sm text-gray-500">Stock visibility, QZ Tray, kitchen printer</p>
                            </a>
                            <a href="settings?s=account" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-user-shield text-teal-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Admin</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-teal-900">Account &amp; profile</h3>
                                <p class="text-sm text-gray-500">Admin username, email, and password</p>
                            </a>
                            <a href="business_settings" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-store text-indigo-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">Business</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-indigo-900">Business info</h3>
                                <p class="text-sm text-gray-500">Name, VAT, receipts, cashier permissions</p>
                            </a>
                            <a href="logs" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-clipboard-list text-blue-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">Audit</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-blue-900">Activity logs</h3>
                                <p class="text-sm text-gray-500">POS and user activity history</p>
                            </a>
                            <a href="add_user" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-cyan-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-user-plus text-cyan-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-cyan-100 text-cyan-700 px-2 py-1 rounded-full">Staff</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-cyan-900">Add users</h3>
                                <p class="text-sm text-gray-500">Create cashier, manager, or waitress logins</p>
                            </a>
                            <a href="settings?s=activation" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-key text-amber-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded-full">License</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-amber-900">Software activation</h3>
                                <p class="text-sm text-gray-500">Enter key and view license status</p>
                            </a>
                            <a href="settings?s=cashout" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-emerald-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-file-invoice-dollar text-emerald-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-1 rounded-full">Month-end</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-emerald-900">Month-end cashout</h3>
                                <p class="text-sm text-gray-500">Report download and transaction cleanup</p>
                            </a>
                            <a href="settings?s=system" class="settings-menu-card group block bg-gray-50 rounded-xl p-5 border border-gray-200 no-underline text-inherit">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 bg-rose-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-database text-rose-600 text-xl"></i>
                                    </div>
                                    <span class="text-xs bg-rose-100 text-rose-700 px-2 py-1 rounded-full">Data</span>
                                </div>
                                <h3 class="font-semibold text-gray-800 mb-1 group-hover:text-rose-900">System management</h3>
                                <p class="text-sm text-gray-500">Reset data, export, barcodes</p>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($settingsSection === 'display'):
                try {
                    $posDb = new PDO('sqlite:../pos.db');
                    // Add column if it doesn't exist
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN hide_available_quantity BOOLEAN NOT NULL DEFAULT 0");
                    } catch (PDOException $e) {
                        // Column already exists, continue
                    }
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN skip_stock_checks BOOLEAN NOT NULL DEFAULT 0");
                    } catch (PDOException $e) {
                        // Column already exists, continue
                    }
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
                    } catch (PDOException $e) {
                        // Column already exists, continue
                    }
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_ip TEXT");
                    } catch (PDOException $e) {
                    }
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_port INTEGER NOT NULL DEFAULT 9100");
                    } catch (PDOException $e) {
                    }
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN cashier_idle_timeout_seconds INTEGER NOT NULL DEFAULT 120");
                    } catch (PDOException $e) {
                    }
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN receipt_paper_width_mm INTEGER NOT NULL DEFAULT 58");
                    } catch (PDOException $e) {
                    }
                    foreach ([
                        "ALTER TABLE product_settings ADD COLUMN gratuity_percent REAL NOT NULL DEFAULT 0",
                        "ALTER TABLE product_settings ADD COLUMN gratuity_default_enabled INTEGER NOT NULL DEFAULT 1",
                        "ALTER TABLE product_settings ADD COLUMN credit_interest_enabled INTEGER NOT NULL DEFAULT 1",
                        "ALTER TABLE product_settings ADD COLUMN credit_interest_rate REAL NOT NULL DEFAULT 18",
                    ] as $gSql) {
                        try {
                            $posDb->exec($gSql);
                        } catch (PDOException $e) {
                        }
                    }

                    $stmt = $posDb->query("SELECT hide_available_quantity, skip_stock_checks, use_qz_tray, kitchen_printer_ip, kitchen_printer_port, cashier_idle_timeout_seconds, receipt_paper_width_mm, gratuity_percent, gratuity_default_enabled, credit_interest_enabled, credit_interest_rate FROM product_settings LIMIT 1");
                    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
                    $hide_available_quantity = $setting['hide_available_quantity'] ?? 0;
                    $hide_available_quantity_checked = $hide_available_quantity ? 'checked' : '';
                    $skip_stock_checks = $setting['skip_stock_checks'] ?? 0;
                    $skip_stock_checks_checked = $skip_stock_checks ? 'checked' : '';
                    $use_qz_tray = $setting['use_qz_tray'] ?? 0;
                    $use_qz_tray_checked = $use_qz_tray ? 'checked' : '';
                    $kitchen_printer_ip_val = htmlspecialchars($setting['kitchen_printer_ip'] ?? '', ENT_QUOTES, 'UTF-8');
                    $kitchen_printer_port_val = (int)($setting['kitchen_printer_port'] ?? 9100);
                    if ($kitchen_printer_port_val <= 0 || $kitchen_printer_port_val > 65535) {
                        $kitchen_printer_port_val = 9100;
                    }
                    $cashier_idle_timeout_seconds_val = (int) ($setting['cashier_idle_timeout_seconds'] ?? 120);
                    if ($cashier_idle_timeout_seconds_val < 30) {
                        $cashier_idle_timeout_seconds_val = 30;
                    }
                    if ($cashier_idle_timeout_seconds_val > 3600) {
                        $cashier_idle_timeout_seconds_val = 3600;
                    }
                    $receipt_paper_width_mm_val = (int)($setting['receipt_paper_width_mm'] ?? 58);
                    if ($receipt_paper_width_mm_val !== 80) {
                        $receipt_paper_width_mm_val = 58;
                    }
                    $gratuity_percent_val = isset($setting['gratuity_percent']) ? round(floatval($setting['gratuity_percent']), 2) : 0;
                    if ($gratuity_percent_val < 0) {
                        $gratuity_percent_val = 0;
                    }
                    if ($gratuity_percent_val > 100) {
                        $gratuity_percent_val = 100;
                    }
                    $gratuity_default_enabled = (int) ($setting['gratuity_default_enabled'] ?? 1);
                    $gratuity_default_enabled_checked = $gratuity_default_enabled ? 'checked' : '';
                    $credit_interest_enabled = !isset($setting['credit_interest_enabled']) || (int) $setting['credit_interest_enabled'] === 1;
                    $credit_interest_enabled_checked = $credit_interest_enabled ? 'checked' : '';
                    $credit_interest_rate_val = isset($setting['credit_interest_rate']) ? round(floatval($setting['credit_interest_rate']), 2) : 18.0;
                    if ($credit_interest_rate_val < 0) {
                        $credit_interest_rate_val = 0;
                    }
                    if ($credit_interest_rate_val > 100) {
                        $credit_interest_rate_val = 100;
                    }
                } catch (PDOException $e) {
                    $hide_available_quantity = 0;
                    $hide_available_quantity_checked = '';
                    $skip_stock_checks = 0;
                    $skip_stock_checks_checked = '';
                    $use_qz_tray = 0;
                    $use_qz_tray_checked = '';
                    $kitchen_printer_ip_val = '';
                    $kitchen_printer_port_val = 9100;
                    $cashier_idle_timeout_seconds_val = 120;
                    $receipt_paper_width_mm_val = 58;
                    $credit_interest_enabled_checked = 'checked';
                    $credit_interest_rate_val = 18.0;
                    error_log("Database error: " . $e->getMessage());
                }
                ?>
                <div class="bg-white shadow-xl rounded-xl p-8 mb-8">
                    <h2 class="text-2xl font-bold mb-6">Display Settings</h2>
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="flex items-center h-5">
                            <input type="checkbox" name="hide_available_quantity" id="hide_available_quantity" class="h-5 w-5 text-gray-600 border-gray-300 rounded focus:ring-gray-500" <?php echo $hide_available_quantity_checked; ?>>
                        </div>
                        <div class="ml-2 text-sm">
                            <label for="hide_available_quantity" class="font-medium text-gray-700 flex items-center cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                    <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                                </svg>
                                Hide available quantity from cashiers
                            </label>
                            <p class="text-xs text-gray-500 mt-1 ml-7">When enabled, cashiers won't see product quantities on the POS. Stock checks during checkout are still enforced unless "Skip stock checks" is enabled.</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3 mb-4 mt-6">
                        <div class="flex items-center h-5">
                            <input type="checkbox" name="skip_stock_checks" id="skip_stock_checks" class="h-5 w-5 text-gray-600 border-gray-300 rounded focus:ring-gray-500" <?php echo $skip_stock_checks_checked; ?>>
                        </div>
                        <div class="ml-2 text-sm">
                            <label for="skip_stock_checks" class="font-medium text-gray-700 flex items-center cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                Skip stock checks during checkout
                            </label>
                            <p class="text-xs text-gray-500 mt-1 ml-7">When enabled, checkout will not block sales for insufficient or zero stock. Use only if you manage stock elsewhere or allow overselling.</p>
                        </div>
                    </div>
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Gratuity (POS)</h3>
                        <p class="text-sm text-gray-600 mb-4">On View Tab, staff can turn gratuity on for a tab. The configured percentage is added to the tab balance (not as a product line) and prints on copy and balance receipts when enabled.</p>
                        <div class="flex flex-wrap items-end gap-4 max-w-xl mb-4">
                            <div>
                                <label for="gratuity_percent_admin" class="block text-sm font-medium text-gray-700 mb-1">Gratuity %</label>
                                <input type="number" id="gratuity_percent_admin" min="0" max="100" step="0.5" value="<?php echo htmlspecialchars((string) $gratuity_percent_val, ENT_QUOTES, 'UTF-8'); ?>" class="block w-36 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                            </div>
                            <button type="button" id="saveGratuityPercentBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">Save %</button>
                        </div>
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="flex items-center h-5">
                                <input type="checkbox" id="gratuity_default_enabled" class="h-5 w-5 text-gray-600 border-gray-300 rounded focus:ring-gray-500" <?php echo $gratuity_default_enabled_checked; ?>>
                            </div>
                            <div class="ml-2 text-sm">
                                <label for="gratuity_default_enabled" class="font-medium text-gray-700 cursor-pointer">Default: gratuity ON when a new tab is opened</label>
                                <p class="text-xs text-gray-500 mt-1 ml-0">Clears when the cart is cleared; cashier can still switch off.</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Credit payment interest</h3>
                        <p class="text-sm text-gray-600 mb-4">When enabled, paying off credit balances adds an interest charge on each payment (shown on receipts and credit transactions). Turn off to collect payments without interest.</p>
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="flex items-center h-5">
                                <input type="checkbox" id="credit_interest_enabled" class="h-5 w-5 text-gray-600 border-gray-300 rounded focus:ring-gray-500" <?php echo $credit_interest_enabled_checked; ?>>
                            </div>
                            <div class="ml-2 text-sm">
                                <label for="credit_interest_enabled" class="font-medium text-gray-700 cursor-pointer">Apply interest on credit payments</label>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-end gap-4 max-w-xl mb-4" id="creditInterestRateRow">
                            <div>
                                <label for="credit_interest_rate_admin" class="block text-sm font-medium text-gray-700 mb-1">Interest rate (%)</label>
                                <input type="number" id="credit_interest_rate_admin" min="0" max="100" step="0.5" value="<?php echo htmlspecialchars((string) $credit_interest_rate_val, ENT_QUOTES, 'UTF-8'); ?>" class="block w-36 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                            </div>
                            <button type="button" id="saveCreditInterestRateBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">Save rate</button>
                        </div>
                    </div>
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Cashier idle logout</h3>
                        <p class="text-sm text-gray-600 mb-4">After this many seconds with no activity on the POS (cashier home), the session logs out automatically when the cart is empty. Mouse movement only counts after the pointer stops briefly.</p>
                        <div class="flex flex-wrap items-end gap-4 max-w-xl">
                            <div>
                                <label for="cashier_idle_timeout_seconds" class="block text-sm font-medium text-gray-700 mb-1">Timeout (seconds)</label>
                                <input type="number" id="cashier_idle_timeout_seconds" name="cashier_idle_timeout_seconds" value="<?php echo (int) $cashier_idle_timeout_seconds_val; ?>" min="30" max="3600" step="1" class="block w-40 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                            </div>
                            <button type="button" id="saveCashierIdleTimeoutBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">Save</button>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">Allowed range: 30–3600 seconds (30 minutes max). Default 120.</p>
                    </div>
                    <div class="flex items-center space-x-3 mb-4 mt-6">
                        <div class="flex items-center h-5">
                            <input type="checkbox" name="use_qz_tray" id="use_qz_tray" class="h-5 w-5 text-gray-600 border-gray-300 rounded focus:ring-gray-500" <?php echo $use_qz_tray_checked; ?>>
                        </div>
                        <div class="ml-2 text-sm">
                            <label for="use_qz_tray" class="font-medium text-gray-700 flex items-center cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                Use QZ Tray printing for receipts
                            </label>
                            <p class="text-xs text-gray-500 mt-1 ml-7">When enabled (desktop/web), receipts will be printed via QZ Tray using <code>qzreceipt.php</code> instead of direct ESC/POS printing via <code>receipt.php</code>. Android will still use <code>receipt.php</code>.</p>
                        </div>
                    </div>
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Receipt paper width</h3>
                        <p class="text-sm text-gray-600 mb-4">Set the customer receipt layout width. 58mm is default and optimized for narrow thermal rolls; 80mm uses wider columns.</p>
                        <div class="flex flex-wrap items-end gap-4 max-w-xl">
                            <div>
                                <label for="receipt_paper_width_mm" class="block text-sm font-medium text-gray-700 mb-1">Paper width</label>
                                <select id="receipt_paper_width_mm" name="receipt_paper_width_mm" class="block w-40 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                                    <option value="58" <?php echo ((int)$receipt_paper_width_mm_val === 58) ? 'selected' : ''; ?>>58 mm</option>
                                    <option value="80" <?php echo ((int)$receipt_paper_width_mm_val === 80) ? 'selected' : ''; ?>>80 mm</option>
                                </select>
                            </div>
                            <button type="button" id="saveReceiptPaperWidthBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">Save width</button>
                        </div>
                    </div>
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Kitchen printer (Add to tab)</h3>
                        <p class="text-sm text-gray-600 mb-4">When cashiers choose &quot;Send to kitchen&quot; on tab orders, the server sends the same kitchen ticket to this ESC/POS printer over TCP (raw port, usually 9100). The PC running PHP must reach this IP on your LAN.</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-xl">
                            <div>
                                <label for="kitchen_printer_ip" class="block text-sm font-medium text-gray-700 mb-1">Kitchen printer IP</label>
                                <input type="text" id="kitchen_printer_ip" name="kitchen_printer_ip" value="<?php echo $kitchen_printer_ip_val; ?>" placeholder="e.g. 192.168.1.50" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                            </div>
                            <div>
                                <label for="kitchen_printer_port" class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                                <input type="number" id="kitchen_printer_port" name="kitchen_printer_port" value="<?php echo (int)$kitchen_printer_port_val; ?>" min="1" max="65535" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                            </div>
                        </div>
                        <button type="button" id="saveKitchenPrinterBtn" class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">Save kitchen printer</button>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const checkbox = document.getElementById('hide_available_quantity');
                        checkbox.checked = <?php echo $hide_available_quantity; ?>;
                        checkbox.addEventListener('change', function() {
                            const hideQuantity = this.checked ? 1 : 0;
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({ hide_available_quantity: hideQuantity })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showAlert('success', 'Success', 'Setting updated successfully');
                                } else {
                                    showAlert('error', 'Error', 'Failed to update setting');
                                    checkbox.checked = !checkbox.checked; // Revert on error
                                }
                            })
                            .catch(error => {
                                showAlert('error', 'Error', 'Failed to update setting');
                                checkbox.checked = !checkbox.checked; // Revert on error
                            });
                        });
                        const skipStockChecksCheckbox = document.getElementById('skip_stock_checks');
                        skipStockChecksCheckbox.checked = <?php echo $skip_stock_checks; ?>;
                        skipStockChecksCheckbox.addEventListener('change', function() {
                            const skipStockChecks = this.checked ? 1 : 0;
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({ skip_stock_checks: skipStockChecks })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showAlert('success', 'Success', 'Setting updated successfully');
                                } else {
                                    showAlert('error', 'Error', 'Failed to update setting');
                                    skipStockChecksCheckbox.checked = !skipStockChecksCheckbox.checked;
                                }
                            })
                            .catch(error => {
                                showAlert('error', 'Error', 'Failed to update setting');
                                skipStockChecksCheckbox.checked = !skipStockChecksCheckbox.checked;
                            });
                        });

                        document.getElementById('saveGratuityPercentBtn').addEventListener('click', function() {
                            let gp = parseFloat(document.getElementById('gratuity_percent_admin').value);
                            if (isNaN(gp)) gp = 0;
                            if (gp < 0) gp = 0;
                            if (gp > 100) gp = 100;
                            document.getElementById('gratuity_percent_admin').value = String(gp);
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ gratuity_percent: gp })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) showAlert('success', 'Success', 'Gratuity % saved');
                                else showAlert('error', 'Error', data.error || 'Failed to save');
                            })
                            .catch(function() { showAlert('error', 'Error', 'Failed to save'); });
                        });

                        const gratDefEn = document.getElementById('gratuity_default_enabled');
                        gratDefEn.addEventListener('change', function() {
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ gratuity_default_enabled: this.checked ? 1 : 0 })
                            }).then(function(r) { return r.json(); }).then(function(data) {
                                if (!data.success) { gratDefEn.checked = !gratDefEn.checked; showAlert('error', 'Error', 'Failed to update'); }
                                else showAlert('success', 'Success', 'Setting updated');
                            }).catch(function() { gratDefEn.checked = !gratDefEn.checked; showAlert('error', 'Error', 'Failed'); });
                        });

                        const creditInterestEnabled = document.getElementById('credit_interest_enabled');
                        const creditInterestRateRow = document.getElementById('creditInterestRateRow');
                        function syncCreditInterestRateRow() {
                            if (creditInterestRateRow) {
                                creditInterestRateRow.style.opacity = creditInterestEnabled.checked ? '1' : '0.5';
                            }
                        }
                        syncCreditInterestRateRow();
                        creditInterestEnabled.addEventListener('change', function() {
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ credit_interest_enabled: this.checked ? 1 : 0 })
                            }).then(function(r) { return r.json(); }).then(function(data) {
                                if (!data.success) {
                                    creditInterestEnabled.checked = !creditInterestEnabled.checked;
                                    showAlert('error', 'Error', 'Failed to update');
                                } else {
                                    syncCreditInterestRateRow();
                                    showAlert('success', 'Success', 'Credit interest setting updated');
                                }
                            }).catch(function() {
                                creditInterestEnabled.checked = !creditInterestEnabled.checked;
                                showAlert('error', 'Error', 'Failed');
                            });
                        });

                        document.getElementById('saveCreditInterestRateBtn').addEventListener('click', function() {
                            let rate = parseFloat(document.getElementById('credit_interest_rate_admin').value);
                            if (isNaN(rate)) rate = 0;
                            if (rate < 0) rate = 0;
                            if (rate > 100) rate = 100;
                            document.getElementById('credit_interest_rate_admin').value = String(rate);
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ credit_interest_rate: rate })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) showAlert('success', 'Success', 'Credit interest rate saved');
                                else showAlert('error', 'Error', data.error || 'Failed to save');
                            })
                            .catch(function() { showAlert('error', 'Error', 'Failed to save'); });
                        });

                        document.getElementById('saveCashierIdleTimeoutBtn').addEventListener('click', function() {
                            let sec = parseInt(document.getElementById('cashier_idle_timeout_seconds').value, 10);
                            if (isNaN(sec)) sec = 120;
                            if (sec < 30) sec = 30;
                            if (sec > 3600) sec = 3600;
                            document.getElementById('cashier_idle_timeout_seconds').value = String(sec);
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ cashier_idle_timeout_seconds: sec })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    showAlert('success', 'Success', 'Cashier idle timeout saved');
                                } else {
                                    showAlert('error', 'Error', data.error || 'Failed to save');
                                }
                            })
                            .catch(function() {
                                showAlert('error', 'Error', 'Failed to save');
                            });
                        });

                        const useQzTrayCheckbox = document.getElementById('use_qz_tray');
                        useQzTrayCheckbox.checked = <?php echo $use_qz_tray ? 1 : 0; ?>;
                        useQzTrayCheckbox.addEventListener('change', function() {
                            const useQzTray = this.checked ? 1 : 0;
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({ use_qz_tray: useQzTray })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showAlert('success', 'Success', 'Setting updated successfully');
                                } else {
                                    showAlert('error', 'Error', 'Failed to update setting');
                                    useQzTrayCheckbox.checked = !useQzTrayCheckbox.checked;
                                }
                            })
                            .catch(error => {
                                showAlert('error', 'Error', 'Failed to update setting');
                                useQzTrayCheckbox.checked = !useQzTrayCheckbox.checked;
                            });
                        });

                        document.getElementById('saveReceiptPaperWidthBtn').addEventListener('click', function() {
                            const paperWidth = parseInt(document.getElementById('receipt_paper_width_mm').value, 10) === 80 ? 80 : 58;
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ receipt_paper_width_mm: paperWidth })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    showAlert('success', 'Success', 'Receipt paper width saved');
                                } else {
                                    showAlert('error', 'Error', data.error || 'Failed to save width');
                                }
                            })
                            .catch(function() {
                                showAlert('error', 'Error', 'Failed to save width');
                            });
                        });

                        document.getElementById('saveKitchenPrinterBtn').addEventListener('click', function() {
                            const ip = document.getElementById('kitchen_printer_ip').value.trim();
                            const port = parseInt(document.getElementById('kitchen_printer_port').value, 10) || 9100;
                            fetch('../update_display_setting.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ kitchen_printer_ip: ip, kitchen_printer_port: port })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    showAlert('success', 'Success', 'Kitchen printer saved');
                                } else {
                                    showAlert('error', 'Error', data.error || 'Failed to save');
                                }
                            })
                            .catch(function() {
                                showAlert('error', 'Error', 'Failed to save kitchen printer');
                            });
                        });
                    });
                </script>
                <?php endif; ?>

                <?php if ($settingsSection === 'account'): ?>
                <div class="bg-white shadow-xl rounded-xl p-8 mb-8">
                        <h2 class="text-2xl font-bold mb-6">Update Account Details</h2>
                        
                        <form action="" method="POST" class="space-y-6" autocomplete="off">
                            <?php
                            try {
                                $pdo = new PDO('sqlite:../user.db');
                                $stmt = $pdo->prepare("SELECT username, email FROM users WHERE role = 'admin'");
                                $stmt->execute();
                                $user = $stmt->fetch();
                                $username = $user ? $user['username'] : '';
                                $email = $user ? $user['email'] : '';
                            } catch (PDOException $e) {
                                // Handle database error appropriately, e.g., log the error
                                $username = '';
                                $email = '';
                            }
                            ?>
                            <!-- Username and Email in one row -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="relative">
                                    <label for="new_username" class="block text-sm font-medium text-gray-700 mb-2">New Username</label>
                                    <div class="relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" />
                                            </svg>
                                        </div>
                                        <input type="text" name="new_username" id="new_username" value="<?php echo htmlspecialchars($username); ?>"
                                            class="block w-full pl-10 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                                    </div>
                                </div>

                                <div class="relative">
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                    <div class="relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                                                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                                            </svg>
                                        </div>
                                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($email); ?>"
                                            class="block w-full pl-10 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                                    </div>
                                </div>
                            </div>

                            <!-- Password fields in one row -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="relative">
                                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                                    <div class="relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <input type="password" name="current_password" id="current_password" required autocomplete="off"
                                            class="block w-full pl-10 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                                    </div>
                                </div>

                                <div class="relative">
                                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                    <div class="relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <input type="password" name="new_password" id="new_password" autocomplete="off"
                                            class="block w-full pl-10 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                                    </div>
                                </div>
                            </div>

                            <div class="mt-2">
                                <button type="submit" name="update_account" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                        <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                                    </svg>
                                    Update
                                </button>
                            </div>

                            <div class="mt-8 pt-6 border-t border-gray-200">
                                <h3 class="text-sm font-semibold text-gray-800 mb-3">Quick links</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <a href="business_settings" class="settings-menu-card group block bg-gray-50 rounded-xl p-4 border border-gray-200 no-underline text-inherit">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center shrink-0">
                                                <i class="fas fa-store text-indigo-600"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <span class="font-semibold text-gray-800 text-sm block group-hover:text-indigo-900">Business info</span>
                                                <span class="text-xs text-gray-500">VAT, receipts, permissions</span>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="logs" class="settings-menu-card group block bg-gray-50 rounded-xl p-4 border border-gray-200 no-underline text-inherit">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center shrink-0">
                                                <i class="fas fa-clipboard-list text-blue-600"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <span class="font-semibold text-gray-800 text-sm block group-hover:text-blue-900">Activity logs</span>
                                                <span class="text-xs text-gray-500">Audit trail</span>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="add_user" class="settings-menu-card group block bg-gray-50 rounded-xl p-4 border border-gray-200 no-underline text-inherit">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-cyan-100 rounded-lg flex items-center justify-center shrink-0">
                                                <i class="fas fa-user-plus text-cyan-600"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <span class="font-semibold text-gray-800 text-sm block group-hover:text-cyan-900">Add users</span>
                                                <span class="text-xs text-gray-500">New staff login</span>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="users" class="settings-menu-card group block bg-gray-50 rounded-xl p-4 border border-gray-200 no-underline text-inherit">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center shrink-0">
                                                <i class="fas fa-users text-teal-600"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <span class="font-semibold text-gray-800 text-sm block group-hover:text-teal-900">Manage users</span>
                                                <span class="text-xs text-gray-500">List and edit accounts</span>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="damaged_goods" class="settings-menu-card group block bg-gray-50 rounded-xl p-4 border border-gray-200 no-underline text-inherit">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center shrink-0">
                                                <i class="fas fa-box-open text-orange-600"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <span class="font-semibold text-gray-800 text-sm block group-hover:text-orange-900">Damaged stock</span>
                                                <span class="text-xs text-gray-500">Write-offs</span>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </form>
                        <?php
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
                            try {
                                $pdo = new PDO('sqlite:../user.db');
                                
                                // Verify current password
                                $stmt = $pdo->prepare("SELECT id, password_hash, email FROM users WHERE role = 'admin'");
                                $stmt->execute();
                                $user = $stmt->fetch();

                                if ($user && md5($_POST['current_password']) === $user['password_hash']) {
                                    $updates = [];
                                    $params = [];

                                    // Handle username update
                                    if (!empty($_POST['new_username'])) {
                                        $updates[] = "username = ?";
                                        $params[] = $_POST['new_username'];
                                    }

                                    // Handle password update
                                    if (!empty($_POST['new_password'])) {
                                        $updates[] = "password_hash = ?";
                                        $params[] = md5($_POST['new_password']);
                                    }

                                    //Handle email update
                                    if (!empty($_POST['email'])) {
                                        $updates[] = "email = ?";
                                        $params[] = $_POST['email'];
                                    }

                                    if (!empty($updates)) {
                                        $params[] = $user['id'];
                                        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
                                        $updateStmt = $pdo->prepare($sql);
                                        $updateStmt->execute($params);
                                        
                                        echo "<script>
                                            showAlert('success', 'Success', 'Account details updated successfully!', 'settings');
                                        </script>";
                                    }
                                } else {
                                    echo "<script>
                                        showAlert('error', 'Error', 'Current password is incorrect.');
                                    </script>";
                                }
                            } catch(PDOException $e) {
                                echo "<script>
                                    showAlert('error', 'Error', " . json_encode('Error updating account details: ' . htmlspecialchars($e->getMessage())) . ");
                                </script>";
                            }
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if ($settingsSection === 'activation'): ?>
                <div class="bg-white shadow-xl rounded-xl p-8 mb-8">
                    <h2 class="text-2xl font-bold mb-6">Software Activation</h2>
                    <form action="" method="POST" class="space-y-4">
                        <!-- CSRF Token for security -->
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateActivationCSRFToken()) ?>">
                        <input type="hidden" name="activate_software" value="1">
                        
                        <div class="relative">
                            <label for="key" class="block text-sm font-medium text-gray-700 mb-2">Activation Key</label>
                            <div class="relative rounded-md shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M18 8a6 6 0 01-7.743 5.743L10 14l-1 1-1 1H6v-1l1-1 1-1-1.243-1.243A6 6 0 1118 8zm-6-4a1 1 0 100 2 2 2 0 012 2 1 1 0 102 0 4 4 0 00-4-4z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <input type="text" name="key" id="key" placeholder="Enter Your Activation Key" required
                                    maxlength="64" autocomplete="off"
                                    class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm placeholder-gray-400">
                            </div>
                        </div>
                        <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                            <svg class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>
                            </svg>
                            Activate
                        </button>
                    </form>

                    <?php
                    // Process activation with secure validation
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_software']) && isset($_POST['key'])) {
                        $submittedKey = trim($_POST['key']);
                        $csrfToken = $_POST['csrf_token'] ?? '';
                        
                        // Use secure activation function with CSRF validation
                        $result = activateKey($submittedKey, $csrfToken);
                        
                        if ($result['success']) {
                            echo "<script>
                                showAlert('success', 'Success', '" . addslashes($result['message']) . "', 'settings');
                            </script>";
                        } else {
                            echo "<script>
                                showAlert('error', 'Error', '" . addslashes($result['message']) . "');
                            </script>";
                        }
                    }

                    // Check activation status with expiration
                    $activationCheck = checkActivationStatus();
                    
                    if ($activationCheck['status'] === 'active') {
                        $expiryMessage = '';
                        if (isset($activationCheck['days_remaining'])) {
                            $days = $activationCheck['days_remaining'];
                            $expiryDate = date('M d, Y', strtotime($activationCheck['expires_at']));
                            
                            if ($days <= 7) {
                                $expiryMessage = "<div class='mt-2 p-3 bg-yellow-50 text-yellow-800 rounded border border-yellow-200'>
                                    <strong>Warning:</strong> Your activation will expire in {$days} day(s) on {$expiryDate}. Please prepare a new activation key.
                                </div>";
                            } else {
                                $expiryMessage = "<div class='mt-2 text-sm text-gray-600'>
                                    Valid until: {$expiryDate} ({$days} days remaining)
                                </div>";
                            }
                        }
                        
                        echo "<div class='mt-4 p-4 bg-blue-100 text-blue-700 rounded fade-in'>
                            <strong>✓ Your account is activated.</strong>
                            {$expiryMessage}
                        </div>";
                    } elseif ($activationCheck['status'] === 'expired') {
                        echo "<div class='mt-4 p-4 bg-red-100 text-red-700 rounded fade-in'>
                            <strong>Activation Expired!</strong> Your activation key has expired on " . date('M d, Y', strtotime($activationCheck['expired_date'])) . ". Please enter a new activation key to continue using the system.
                        </div>";
                    } else {
                        echo "<div class='mt-4 p-4 bg-yellow-100 text-yellow-700 rounded fade-in'>
                            <h2 class='font-bold'>Payment Methods</h2>
                            <p>Your account is not activated. Please contact <a href='mailto:info.easystockna@gmail.com' class='text-blue-600 underline'>info.easystockna@gmail.com</a> or call 0814759498 to purchase a key.</p><br>
                            <div class='mt-2 flex space-x-4'>
                                <div class='flex items-center'>
                                    <img src='../props/FNB_Color.png' alt='FNB eWallet' class='h-12 w-12'>
                                    <span class='ml-2'>FNB eWallet</span>
                                </div>
                                <div class='flex items-center'>
                                    <img src='../props/1677845410-33-bank-windhoek.png' alt='Bank Windhoek Blue Wallet' class='h-12 w-12'>
                                    <span class='ml-2'>Bank Windhoek Blue Wallet</span>
                                </div>
                                <div class='flex items-center'>
                                    <img src='../props/standard-bank-group-logo.png' alt='Standard Bank Easy Wallet' class='h-12 w-12'>
                                    <span class='ml-2'>Standard Bank Easy Wallet</span>
                                </div>
                                <div class='flex items-center'>
                                    <img src='../props/nedbank-logo-web.jpg' alt='Nedbank' class='h-12 w-12'>
                                    <span class='ml-2'>Nedbank</span>
                                </div>
                            </div>
                        </div>";
                    }
                    ?>
                </div>
                <?php endif; ?>

                <?php if ($settingsSection === 'cashout'): ?>
                <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
                    <h2 class="text-xl font-semibold mb-2">Month-End Cashout</h2>
                    <p class="text-gray-600 mb-4">Perform end-of-month cashout operation. This will automatically generate a monthly report and then clear all transactions while preserving any unpaid credit balances.</p>
                    <div class="flex space-x-2 mb-4">
                        <?php
                        // Check activation status using secure helper
                        $cashoutActivationCheck = checkActivationStatus();
                        $isCashoutActivated = ($cashoutActivationCheck['status'] === 'active');
                        ?>
                        
                        <button type="button" id="perform_cashout_btn" class="bg-teal-500 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded flex items-center transition duration-200" <?php echo !$isCashoutActivated ? 'disabled title="Please activate the software to use this feature"' : ''; ?>>
                            <svg class="w-5 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                            Generate Report & Cashout
                        </button>
                    </div>

                    <!-- Hidden form for submission -->
                    <form id="perform_cashout_form" method="POST" action="" style="display: none;">
                        <input type="hidden" name="perform_cashout" value="1">
                    </form>

                    <script>
                        document.getElementById('perform_cashout_btn').addEventListener('click', function() {
                            if (this.hasAttribute('disabled')) {
                                showAlert('warning', 'Activation Required', 'You need to activate the software to use cashout. Open <strong>Settings → Software activation</strong> and enter your key.');
                                return;
                            }
                            
                            showConfirm(
                                'Perform Month-End Cashout', 
                                'This process will download your monthly report and delete all records EXCEPT unpaid creditor transactions. <br><br><strong>Important:</strong> Please make sure you save the downloaded report file safely. <br><br>Do you want to proceed?',
                                function() {
                                    document.getElementById('perform_cashout_form').submit();
                                }
                            );
                        });
                    </script>
                </div>
                <?php endif; ?>

                <?php if ($settingsSection === 'system'): ?>
                <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
                    <h2 class="text-xl font-semibold mb-4">System Management</h2>
                    <div class="flex space-x-2"> <!-- Small gap between buttons -->
                        <button type="button" id="delete_all_btn" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded flex items-center transition duration-200">
                            <svg class="w-5 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                            Reset All Records
                        </button>
                        
                        <button type="button" id="delete_all_products_btn" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded flex items-center transition duration-200">
                            <svg class="w-5 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                            Delete All Products
                        </button>

                        <button type="button" id="reset_quantities_btn" class="bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded flex items-center transition duration-200">
                            <svg class="w-5 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            Reset All Quantities
                        </button>

                        <a href="generate_barcodes_pdf.php" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded flex items-center transition duration-200">
                            <svg class="w-5 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Generate Barcode PDF
                        </a>
                        
                        <button type="button" id="export_transactions_btn" class="bg-teal-500 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded flex items-center transition duration-200">
                            <svg class="w-5 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Export Transactions
                        </button>

                        <button type="button" id="export_product_images_btn" class="bg-indigo-500 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded flex items-center transition duration-200">
                            <svg class="w-5 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            Export Product Images
                        </button>
                    </div>
                    <p class="text-sm text-gray-500 mt-4">Product images are copied to the <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">product_image_exports</code> folder using each product name, for example <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">castle_lite_750ml.png</code>. Products without a custom image are skipped.

                    <!-- Export Transactions Modal -->
                    <div id="export_modal" class="fixed inset-0 flex items-center justify-center z-50 hidden">
                        <div class="absolute inset-0 bg-black opacity-50"></div>
                        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4 z-10 transform transition-all">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Export Transactions to Excel</h3>
                            <form action="export_transactions.php" method="GET">
                                <div class="mb-4">
                                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                    <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-01'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div class="mb-4">
                                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                    <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div class="flex justify-end space-x-3 mt-5">
                                    <button type="button" id="export_cancel" class="inline-flex justify-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        Cancel
                                    </button>
                                    <button type="submit" class="inline-flex justify-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        Export
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Hidden forms for submissions -->
                    <form id="delete_all_form" method="POST" action="" style="display: none;">
                        <input type="hidden" name="delete_all" value="1">
                    </form>
                    <form id="delete_all_products_form" method="POST" action="" style="display: none;">
                        <input type="hidden" name="delete_all_products" value="1">
                    </form>
                    <form id="reset_quantities_form" method="POST" action="" style="display: none;">
                        <input type="hidden" name="reset_quantities" value="1">
                    </form>
                    <form id="export_product_images_form" method="POST" action="export_product_images.php" style="display: none;"></form>

                    <script>
                        document.getElementById('delete_all_btn').addEventListener('click', function() {
                            showConfirm(
                                'Delete All Records', 
                                'Are you sure you want to delete ALL records? This action cannot be undone.',
                                function() {
                                    document.getElementById('delete_all_form').submit();
                                }
                            );
                        });

                        document.getElementById('delete_all_products_btn').addEventListener('click', function() {
                            showConfirm(
                                'Delete All Products', 
                                'Are you sure you want to delete ALL products? This action cannot be undone.',
                                function() {
                                    document.getElementById('delete_all_products_form').submit();
                                }
                            );
                        });

                        document.getElementById('reset_quantities_btn').addEventListener('click', function() {
                            showConfirm(
                                'Reset All Quantities', 
                                'Are you sure you want to reset ALL product quantities to zero? This action cannot be undone.',
                                function() {
                                    document.getElementById('reset_quantities_form').submit();
                                }
                            );
                        });
                        
                        // Export transactions modal
                        document.getElementById('export_transactions_btn').addEventListener('click', function() {
                            document.getElementById('export_modal').classList.remove('hidden');
                        });
                        
                        document.getElementById('export_cancel').addEventListener('click', function() {
                            document.getElementById('export_modal').classList.add('hidden');
                        });

                        document.getElementById('export_product_images_btn').addEventListener('click', function() {
                            showConfirm(
                                'Export Product Images',
                                'Copy all product images to the <strong>product_image_exports</strong> folder using each product name (for example, castle_lite_750ml.png)? Existing files with the same name will be overwritten.',
                                function() {
                                    document.getElementById('export_product_images_form').submit();
                                }
                            );
                        });

                        const imageExportStatus = new URLSearchParams(window.location.search).get('images_export');
                        if (imageExportStatus === 'success') {
                            const params = new URLSearchParams(window.location.search);
                            const exported = params.get('exported') || '0';
                            const skipped = params.get('skipped') || '0';
                            const failed = params.get('failed') || '0';
                            const folder = params.get('folder') || 'product_image_exports';
                            showAlert(
                                'success',
                                'Images Exported',
                                `Exported ${exported} image(s) to <strong>${folder}</strong>. Skipped ${skipped} product(s) without a custom image.` +
                                (parseInt(failed, 10) > 0 ? `<br><br>${failed} image(s) could not be copied.` : ''),
                                'settings?s=system'
                            );
                        } else if (imageExportStatus === 'error') {
                            const params = new URLSearchParams(window.location.search);
                            const message = params.get('message') || 'Product image export failed.';
                            showAlert('error', 'Export Failed', message, 'settings?s=system');
                        }
                    </script>
                </div>
                <?php endif; ?>

            </div>

            <?php
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all'])) {
                try {
                    // Database connection
                    $db = new PDO('sqlite:../pos.db');
                    
                    // Enable foreign key support for SQLite
                    $db->exec('PRAGMA foreign_keys = OFF');
                    
                    // All transactional tables from pos.db.sql (not products / product_settings / users)
                    posDbDeleteAllFromTables($db, posDbTransactionTables());
                    posDbResetSqliteSequences($db, posDbTransactionTables());

                    // Re-enable foreign key support
                    $db->exec('PRAGMA foreign_keys = ON');
                    
                    // Show success message with modal
                    echo "<script>
                        showAlert('success', 'Success', 'All records have been deleted successfully.', 'settings');
                    </script>";
                    
                } catch(PDOException $e) {
                    echo "<script>
                        showAlert('error', 'Error', " . json_encode('Error: ' . htmlspecialchars($e->getMessage())) . ");
                    </script>";
                }
            }

            // Handler for deleting all products
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all_products'])) {
                try {
                    // Database connection
                    $db = new PDO('sqlite:../pos.db');
                    
                    // Enable foreign key support for SQLite
                    $db->exec('PRAGMA foreign_keys = OFF');
                    
                    // Delete all products
                    $db->exec("DELETE FROM products");
                    
                    // Re-enable foreign key support
                    $db->exec('PRAGMA foreign_keys = ON');
                    
                    // Show success message with modal
                    echo "<script>
                        showAlert('success', 'Success', 'All products have been deleted successfully.', 'settings');
                    </script>";
                    
                } catch(PDOException $e) {
                    echo "<script>
                        showAlert('error', 'Error', " . json_encode('Error: ' . htmlspecialchars($e->getMessage())) . ");
                    </script>";
                }
            }

            // Handler for resetting all quantities
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_quantities'])) {
                try {
                    // Database connection
                    $db = new PDO('sqlite:../pos.db');
                    
                    // Update all products to set quantity to 0
                    $db->exec("UPDATE products SET quantity = 0");
                    
                    // Show success message with modal
                    echo "<script>
                        showAlert('success', 'Success', 'All product quantities have been reset to zero.', 'settings');
                    </script>";
                    
                } catch(PDOException $e) {
                    echo "<script>
                        showAlert('error', 'Error', " . json_encode('Error: ' . htmlspecialchars($e->getMessage())) . ");
                    </script>";
                }
            }

            // Handler for performing cashout
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['perform_cashout'])) {
                try {
                    // Check activation status using secure helper
                    $cashoutActivation = checkActivationStatus();
                    
                    if ($cashoutActivation['status'] !== 'active') {
                        $message = $cashoutActivation['status'] === 'expired' 
                            ? 'Your activation has expired. Please enter a new activation key.'
                            : 'You need to activate the software to use the cashout feature. Please enter your activation key above.';
                        echo "<script>
                            showAlert('warning', 'Activation Required', '" . addslashes($message) . "');
                        </script>";
                        return;
                    }
                    
                    // STEP 1: Generate report information before deleting any data
                    $currentMonth = date('m');
                    $currentYear = date('Y');
                    $reportName = 'Monthly_Report_' . date('F_Y') . '.pdf';
                    $reportUrl = "generate_monthly_report.php?month={$currentMonth}&year={$currentYear}&download=1";
                    
                    // STEP 2: Trigger report download first before any data deletion
                    echo "<script>
                        // Show loading spinner
                        var loadingOverlay = document.createElement('div');
                        loadingOverlay.id = 'cashout-loader';
                        loadingOverlay.className = 'fixed inset-0 flex items-center justify-center z-50 bg-black bg-opacity-50';
                        loadingOverlay.innerHTML = `
                            <div class='bg-white p-6 rounded-lg shadow-xl max-w-md w-full'>
                                <div class='flex flex-col items-center'>
                                    <div class='mb-4 text-gray-600 text-center'>
                                        <i class='fa-solid fa-cart-shopping fa-bounce'></i>
                                    </div>
                                    <h3 class='mb-1 text-lg font-semibold text-gray-900'>Processing Cashout</h3>
                                    <div class='w-full bg-gray-200 rounded-full h-2.5 mb-4'>
                                        <div id='progress-bar' class='bg-gray-600 h-2.5 rounded-full' style='width: 0%'></div>
                                    </div>
                                    <p id='process-status' class='text-sm text-gray-500'>Generating monthly report...</p>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(loadingOverlay);

                        // Animate the progress bar
                        var progressBar = document.getElementById('progress-bar');
                        var processStatus = document.getElementById('process-status');
                        var width = 0;
                        var interval = setInterval(function() {
                            if (width >= 100) {
                                clearInterval(interval);
                            } else if (width < 40) {
                                width += 1;
                                progressBar.style.width = width + '%';
                            } else if (width === 40) {
                                processStatus.innerText = 'Downloading report...';
                                width += 1;
                                progressBar.style.width = width + '%';
                            } else if (width < 70) {
                                width += 0.5;
                                progressBar.style.width = width + '%';
                            } else if (width === 70) {
                                processStatus.innerText = 'Preparing database cleanup...';
                                width += 1;
                                progressBar.style.width = width + '%';
                            } else {
                                width += 0.2;
                                progressBar.style.width = width + '%';
                            }
                        }, 50);

                        // Create a download link for the report
                        var downloadLink = document.createElement('a');
                        downloadLink.href = '{$reportUrl}';
                        downloadLink.setAttribute('download', '{$reportName}');
                        downloadLink.style.display = 'none';
                        document.body.appendChild(downloadLink);
                        downloadLink.click();
                        document.body.removeChild(downloadLink);
                        
                        // Wait for the download to start before proceeding with deletion
                        setTimeout(function() {
                            processStatus.innerText = 'Finalizing cashout process...';

                            // Now submit a form to process the data deletion
                            var form = document.createElement('form');
                            form.method = 'POST';
                            form.action = '';
                            
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'complete_cashout';
                            input.value = '1';
                            
                            form.appendChild(input);
                            document.body.appendChild(form);
                            form.submit();
                        }, 3000); // Give 3 seconds for download to start
                    </script>";
                } catch(PDOException $e) {
                    echo "<script>
                        showAlert('error', 'Error', " . json_encode('Error during cashout: ' . htmlspecialchars($e->getMessage())) . ");
                    </script>";
                }
            }
            
            // Handler for completing cashout after report generation
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['complete_cashout'])) {
                try {
                    // Database connection with optimized settings
                    $db = new PDO('sqlite:../pos.db');
                    $db->setAttribute(PDO::ATTR_TIMEOUT, 60); // Increase timeout for large operations
                    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Disable emulated prepares for better performance
                    
                    // Set pragmas before beginning transaction
                    $db->exec('PRAGMA foreign_keys = OFF');
                    $db->exec('PRAGMA journal_mode = MEMORY'); // Use memory journaling for speed
                    $db->exec('PRAGMA synchronous = OFF'); // Disable synchronous mode for speed
                    
                    // Begin transaction for better performance
                    $db->beginTransaction();
                    
                    // First, identify unpaid credit transactions with a single optimized query
                    $creditQuery = $db->query("
                        SELECT 
                            cs.id as sale_id,
                            cs.creditor_id,
                            cs.total_amount,
                            cs.paid_amount,
                            cs.created_at,
                            cs.cashier_id,
                            csi.product_name,
                            csi.quantity,
                            csi.price,
                            c.id as creditor_id
                        FROM credit_sales cs
                        JOIN credit_sale_items csi ON cs.id = csi.sale_id
                        JOIN creditors c ON cs.creditor_id = c.id
                        WHERE cs.payment_status = 'unpaid'
                    ");
                    
                    // Check if the query returned any results
                    $unpaidCreditData = $creditQuery ? $creditQuery->fetchAll(PDO::FETCH_ASSOC) : [];
                    
                    // Extract unique creditor IDs from unpaid transactions
                    $unpaidCreditorIds = [];
                    $reinsertData = [
                        'sales' => [],
                        'items' => []
                    ];
                    
                    foreach ($unpaidCreditData as $data) {
                        $unpaidCreditorIds[$data['creditor_id']] = true;
                        
                        // Prepare data for reinsertion in a more optimized way
                        $saleId = $data['sale_id'];
                        if (!isset($reinsertData['sales'][$saleId])) {
                            $reinsertData['sales'][$saleId] = [
                                'id' => $saleId,
                                'creditor_id' => $data['creditor_id'],
                                'total_amount' => $data['total_amount'],
                                'paid_amount' => $data['paid_amount'],
                                'created_at' => $data['created_at'],
                                'cashier_id' => $data['cashier_id'] ?? 'Unknown'
                            ];
                        }
                        
                        $reinsertData['items'][] = [
                            'sale_id' => $saleId,
                            'product_name' => $data['product_name'],
                            'quantity' => $data['quantity'],
                            'price' => $data['price']
                        ];
                    }
                    
                    $unpaidCreditorIds = array_keys($unpaidCreditorIds);
                    
                    // Build a single DELETE statement for creditors that aren't in unpaid list
                    if (!empty($unpaidCreditorIds)) {
                        $placeholders = implode(',', array_fill(0, count($unpaidCreditorIds), '?'));
                        $preserveCreditorsSql = "DELETE FROM creditors WHERE id NOT IN ({$placeholders})";
                        $preserveCreditorStmt = $db->prepare($preserveCreditorsSql);
                        
                        // Clear all pos.db.sql transaction tables except creditors (trimmed next)
                        posDbDeleteAllFromTables($db, posDbTransactionTablesWithoutCreditors());
                        
                        // Execute preserve creditors statement
                        $preserveCreditorStmt->execute($unpaidCreditorIds);
                        
                        // Prepare statements once and reuse for better performance
                        $insertSaleStmt = $db->prepare("
                            INSERT INTO credit_sales (id, creditor_id, total_amount, paid_amount, payment_status, created_at, cashier_id)
                            VALUES (?, ?, ?, ?, 'unpaid', ?, ?)
                        ");
                        
                        $insertItemStmt = $db->prepare("
                            INSERT INTO credit_sale_items (sale_id, product_name, quantity, price)
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        // Batch process sales reinsertions
                        foreach ($reinsertData['sales'] as $sale) {
                            $insertSaleStmt->execute([
                                $sale['id'],
                                $sale['creditor_id'],
                                $sale['total_amount'],
                                $sale['paid_amount'],
                                $sale['created_at'],
                                $sale['cashier_id']
                            ]);
                        }
                        
                        // Batch process items reinsertions
                        foreach ($reinsertData['items'] as $item) {
                            $insertItemStmt->execute([
                                $item['sale_id'],
                                $item['product_name'],
                                $item['quantity'],
                                $item['price']
                            ]);
                        }

                        posDbResetSqliteSequences($db, posDbTransactionTables());
                        posDbResequenceAfterExplicitInserts($db, 'credit_sales');
                        posDbResequenceAfterExplicitInserts($db, 'credit_sale_items');
                        posDbResequenceAfterExplicitInserts($db, 'creditors');
                    } else {
                        posDbDeleteAllFromTables($db, posDbTransactionTables());
                        posDbResetSqliteSequences($db, posDbTransactionTables());
                    }
                    
                    // Commit the transaction
                    $db->commit();
                    
                    // Reset pragmas after transaction is committed
                    $db->exec('PRAGMA foreign_keys = ON');
                    $db->exec('PRAGMA synchronous = NORMAL');
                    $db->exec('PRAGMA journal_mode = DELETE');
                    
                    // Show success message immediately with improved visibility
                    echo "<script>
                        // Remove the loader if it exists
                        var loader = document.getElementById('cashout-loader');
                        if (loader) {
                            document.body.removeChild(loader);
                        }
                        
                        // Ensure the alert shows up immediately
                        setTimeout(function() {
                            showAlert('success', 'Cashout Complete', 'Cashout completed successfully! All records have been deleted except for unpaid creditor transactions.', 'settings');
                        }, 100);
                    </script>";
                    
                } catch(PDOException $e) {
                    // Attempt to rollback if there was an error
                    if (isset($db) && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    
                    echo "<script>
                        // Remove the loader if it exists
                        var loader = document.getElementById('cashout-loader');
                        if (loader) {
                            document.body.removeChild(loader);
                        }
                        
                        // Show error immediately
                        setTimeout(function() {
                            showAlert('error', 'Error', " . json_encode('Error during cashout: ' . htmlspecialchars($e->getMessage())) . ");
                        }, 100);
                    </script>";
                }
            }
            ?>

            
        </div>

        
    </div>

    
</body>
</html>
