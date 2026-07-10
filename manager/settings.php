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

require_once __DIR__ . '/../manager_pin_helper.php';
$manager_pin_configured = false;
try {
    $manager_pin_configured = managerVoidPinIsConfigured();
} catch (Throwable $e) {
}

$manager_pin_error = '';
$manager_pin_success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manager_void_pin'])) {
    $newPin = trim($_POST['manager_void_pin_new'] ?? '');
    $confirm = trim($_POST['manager_void_pin_confirm'] ?? '');
    if (strlen($newPin) < 4) {
        $manager_pin_error = 'PIN must be at least 4 characters.';
    } elseif ($newPin !== $confirm) {
        $manager_pin_error = 'PIN confirmation does not match.';
    } else {
        try {
            setManagerVoidPin($newPin);
            $manager_pin_success = true;
            $manager_pin_configured = true;
        } catch (Throwable $e) {
            $manager_pin_error = $e->getMessage();
        }
    }
}
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
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Settings</h1>
                    </div>
                </div>

                <?php
                try {
                    $posDb = new PDO('sqlite:../pos.db');
                    // Add column if it doesn't exist
                    try {
                        $posDb->exec("ALTER TABLE product_settings ADD COLUMN hide_available_quantity BOOLEAN NOT NULL DEFAULT 0");
                    } catch (PDOException $e) {
                        // Column already exists, continue
                    }
                    $stmt = $posDb->query("SELECT hide_available_quantity FROM product_settings LIMIT 1");
                    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
                    $hide_available_quantity = $setting['hide_available_quantity'] ?? 0;
                    $hide_available_quantity_checked = $hide_available_quantity ? 'checked' : '';
                } catch (PDOException $e) {
                    $hide_available_quantity = 0;
                    $hide_available_quantity_checked = '';
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
                                Hide available quantity from cashiers (skip stock checks)
                            </label>
                            <p class="text-xs text-gray-500 mt-1 ml-7">When enabled, cashiers won't see product quantities and stock validation will be skipped during checkout</p>
                        </div>
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
                    });
                </script>

                <div class="bg-white shadow-xl rounded-xl p-8 mb-8">
                    <h2 class="text-2xl font-bold mb-2">Manager void PIN</h2>
                    <p class="text-sm text-gray-600 mb-6">Required to void or delete transactions from Reports. Stored securely in the business info database.</p>
                    <?php if ($manager_pin_configured): ?>
                    <p class="text-sm text-teal-700 mb-4 font-medium">A manager PIN is currently set.</p>
                    <?php else: ?>
                    <p class="text-sm text-amber-700 mb-4 font-medium">No PIN set yet — voiding transactions from reports will be blocked until you set one.</p>
                    <?php endif; ?>
                    <form method="POST" action="" class="space-y-4 max-w-md" autocomplete="off">
                        <input type="hidden" name="save_manager_void_pin" value="1">
                        <div>
                            <label for="manager_void_pin_new" class="block text-sm font-medium text-gray-700 mb-1">New PIN</label>
                            <input type="password" name="manager_void_pin_new" id="manager_void_pin_new" autocomplete="off" inputmode="numeric" minlength="4" required
                                class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-gray-600 focus:border-transparent">
                        </div>
                        <div>
                            <label for="manager_void_pin_confirm" class="block text-sm font-medium text-gray-700 mb-1">Confirm PIN</label>
                            <input type="password" name="manager_void_pin_confirm" id="manager_void_pin_confirm" autocomplete="off" inputmode="numeric" minlength="4" required
                                class="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-gray-600 focus:border-transparent">
                        </div>
                        <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-gray-800 hover:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-700 transition-colors duration-200">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                            Save manager PIN
                        </button>
                    </form>
                </div>

                <?php if ($manager_pin_success): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        showAlert('success', 'Success', 'Manager void PIN saved.');
                    });
                </script>
                <?php elseif ($manager_pin_error !== ''): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        showAlert('error', 'Error', <?= json_encode($manager_pin_error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    });
                </script>
                <?php endif; ?>

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

                            <div class="flex gap-8">
                                <button type="submit" name="update_account" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                        <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                                    </svg>
                                    Update
                                </button>
                                <a href="users" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-lg shadow-sm bg-transparent text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                    </svg>
                                    Manage Users
                                </a>
                                <a href="logs" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-lg shadow-sm bg-transparent text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    View Logs
                                </a>

                                <a href="damaged_goods" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-lg shadow-sm bg-transparent text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    Damaged Stock
                                </a>
                                <a href="business_settings" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-lg shadow-sm bg-transparent text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                  info
                                </a>
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
                                showAlert('success', 'Success', '" . addslashes($result['message']) . "');
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
                                showAlert('warning', 'Activation Required', 'You need to activate the software to use the cashout feature. Please enter your activation key above.');
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
                <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
                    <h2 class="text-xl font-semibold mb-4">System Management</h2>
                    <div class="flex space-x-2"> <!-- Small gap between buttons -->
        
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
                    </div>

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
                    </script>
                </div>
            </div>

            <?php
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all'])) {
                try {
                    // Database connection
                    $db = new PDO('sqlite:../pos.db');
                    
                    // Enable foreign key support for SQLite
                    $db->exec('PRAGMA foreign_keys = OFF');
                    
                    // Delete all records from all transaction and log tables
                    $db->exec("DELETE FROM orders");
                    $db->exec("DELETE FROM order_items");
                    
                    // Add credit-related table deletions
                    $db->exec("DELETE FROM credit_sale_items");
                    $db->exec("DELETE FROM credit_sales");
                    $db->exec("DELETE FROM payments");
                    $db->exec("DELETE FROM payment_logs");
                    $db->exec("DELETE FROM cash_transactions"); 
                    $db->exec("DELETE FROM credit_book");
                    $db->exec("DELETE FROM credit_returns");
                    $db->exec("DELETE FROM stock_changes");
                    $db->exec("DELETE FROM damaged_goods");
                    $db->exec("DELETE FROM creditors");
                    $db->exec("DELETE FROM eft_payments");
                    $db->exec("DELETE FROM mixed_payments");
                    $db->exec("DELETE FROM opening_stock");
                    $db->exec("DELETE FROM closing_stock");
                    $db->exec("DELETE FROM daily_stock_summary");
                    $db->exec("DELETE FROM cash_up_summary");
                    $db->exec("DELETE FROM user_log");
                    
                    // Delete tab-related tables
                    $db->exec("DELETE FROM tab_item_payments");
                    $db->exec("DELETE FROM tab_items");
                    $db->exec("DELETE FROM tab_payments");
                    $db->exec("DELETE FROM tabs");
                    
                    // Delete refund and void transaction tables
                    $db->exec("DELETE FROM refund_items");
                    $db->exec("DELETE FROM refunds");
                    $db->exec("DELETE FROM void_transactions");

                    
                    // Re-enable foreign key support
                    $db->exec('PRAGMA foreign_keys = ON');
                    
                    // Show success message with modal
                    echo "<script>
                        showAlert('success', 'Success', 'All records have been deleted successfully.');
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
                        showAlert('success', 'Success', 'All products have been deleted successfully.');
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
                        showAlert('success', 'Success', 'All product quantities have been reset to zero.');
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
                                'created_at' => $data['created_at']
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
                        
                        // Clear all tables in a batch for efficiency
                        $db->exec("DELETE FROM orders");
                        $db->exec("DELETE FROM order_items");
                        $db->exec("DELETE FROM credit_sale_items");
                        $db->exec("DELETE FROM credit_sales");
                        $db->exec("DELETE FROM payments");
                        $db->exec("DELETE FROM payment_logs");
                        $db->exec("DELETE FROM cash_transactions"); 
                        $db->exec("DELETE FROM credit_book");
                        $db->exec("DELETE FROM credit_returns");
                        $db->exec("DELETE FROM stock_changes");
                        $db->exec("DELETE FROM damaged_goods");
                        $db->exec("DELETE FROM eft_payments");
                        $db->exec("DELETE FROM mixed_payments");
                        $db->exec("DELETE FROM opening_stock");
                        $db->exec("DELETE FROM closing_stock");
                        $db->exec("DELETE FROM daily_stock_summary");
                        $db->exec("DELETE FROM cash_up_summary");
                        $db->exec("DELETE FROM user_log");
                        
                        // Delete tab-related tables
                        $db->exec("DELETE FROM tab_item_payments");
                        $db->exec("DELETE FROM tab_items");
                        $db->exec("DELETE FROM tab_payments");
                        $db->exec("DELETE FROM tabs");
                        
                        // Delete refund and void transaction tables
                        $db->exec("DELETE FROM refund_items");
                        $db->exec("DELETE FROM refunds");
                        $db->exec("DELETE FROM void_transactions");
                        
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
                    } else {
                        // If no unpaid creditors, delete all records in one go
                        $tables = [
                            "orders", "order_items", "credit_sale_items", "credit_sales", 
                            "payments", "payment_logs", "cash_transactions", "credit_book", 
                            "credit_returns", "stock_changes", "damaged_goods", "creditors", 
                            "eft_payments", "mixed_payments", "opening_stock", "closing_stock", 
                            "daily_stock_summary", "cash_up_summary", "user_log",
                            "tab_item_payments", "tab_items", "tab_payments", "tabs",
                            "refund_items", "refunds", "void_transactions"
                        ];
                        
                        foreach ($tables as $table) {
                            $db->exec("DELETE FROM {$table}");
                        }
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
                            showAlert('success', 'Cashout Complete', 'Cashout completed successfully! All records have been deleted except for unpaid creditor transactions.');
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
