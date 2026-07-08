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

// Include activation helper
require_once 'activation_helper.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <link rel="icon" href="favicon.ico" type="image/png">

    <style>
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
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            .content {
                margin-left: 0 !important;
            }
            
            .container {
                padding: 1rem;
            }
            
            /* Card padding adjustments */
            .bg-white.shadow-xl.rounded-xl {
                padding: 1rem !important;
            }
            
            /* Grid gap adjustments */
            .grid.gap-6 {
                gap: 1rem !important;
            }
            
            /* Text size adjustments */
            .text-2xl {
                font-size: 1.5rem !important;
            }
            
            .text-3xl {
                font-size: 1.875rem !important;
            }
            
            /* Form input adjustments */
            input[type="text"],
            input[type="email"],
            input[type="password"] {
                font-size: 0.875rem !important;
                padding: 0.5rem !important;
            }
            
            /* Button adjustments */
            button.px-6 {
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
                font-size: 0.875rem !important;
            }
            
            /* Space adjustments */
            .space-y-6 > * + * {
                margin-top: 1rem !important;
            }
            
            .mb-8 {
                margin-bottom: 1.5rem !important;
            }
            
            .mb-6 {
                margin-bottom: 1rem !important;
            }
            
            /* Form spacing adjustments */
            .space-y-4 > * + * {
                margin-top: 0.75rem !important;
            }
            
            .space-y-6 > * + * {
                margin-top: 1rem !important;
            }
            
            /* Grid column adjustments */
            .grid.grid-cols-1.md\\:grid-cols-2 {
                grid-template-columns: 1fr !important;
            }
            
            /* Label and input spacing */
            label.block {
                margin-bottom: 0.5rem !important;
            }
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000;
        }
        
        /* Ensure sidebar is above overlay on mobile */
        @media (max-width: 1023px) {
            #sidebar {
                z-index: 10000 !important;
            }
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
            z-index: 80;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        @media (min-width: 1024px) {
            .hamburger {
                display: none;
            }
            .mobile-overlay {
                display: none;
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-4 lg:p-6">
                <!-- Header Row: Title -->
                <div class="sticky top-0 z-50 bg-gray-50 py-3 lg:py-4 mb-4 lg:mb-6 flex items-center justify-between gap-4 -mx-4 lg:-mx-6 px-4 lg:px-6 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold">Settings</h1>
                    </div>
                </div>



                <?php
try {
    $pdo = new PDO('sqlite:pos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        show_all_products BOOLEAN NOT NULL DEFAULT 0,
        hide_available_quantity BOOLEAN NOT NULL DEFAULT 0,
        default_print_receipt BOOLEAN NOT NULL DEFAULT 0
    )");
    
    // Check if row exists, if not create it
    $checkStmt = $pdo->query("SELECT COUNT(*) FROM product_settings WHERE id = 1");
    $rowExists = $checkStmt->fetchColumn();
    if ($rowExists == 0) {
        $pdo->exec("INSERT INTO product_settings (id, show_all_products, hide_available_quantity, default_print_receipt) VALUES (1, 0, 0, 0)");
    }
    
    $stmt = $pdo->query("SELECT show_all_products FROM product_settings WHERE id = 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    $show_all_products = $setting['show_all_products'] ?? 0; // Default to 0 if not set
    $show_all_products_checked = $show_all_products ? 'checked' : '';
} catch (PDOException $e) {
    $show_all_products = 0; // Default to unchecked if database error occurs
    $show_all_products_checked = '';
    error_log("Database error: " . $e->getMessage()); // Log the error for debugging
}
?>
<div class="bg-white shadow-xl rounded-xl p-4 lg:p-8 mb-6 lg:mb-8 relative z-10">
    <h2 class="text-xl lg:text-2xl font-bold mb-4 lg:mb-6">Display Settings</h2>
    <div class="flex items-center space-x-3">
        <div class="flex items-center h-5">
            <input type="checkbox" name="show_all_products" id="show_all_products" class="h-5 w-5 text-gray-600 border-gray-300 rounded focus:ring-gray-500" <?php echo $show_all_products_checked; ?>>
        </div>
        <div class="ml-2 text-sm">
            <label for="show_all_products" class="font-medium text-gray-700 flex items-center cursor-pointer">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                    <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                </svg>
                Show all products on dashboard (including unavailable products)
            </label>
            <p class="text-xs text-gray-500 mt-1 ml-7">When enabled, all products will be displayed on the dashboard regardless of availability</p>
        </div>
    </div>
</div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const checkbox = document.getElementById('show_all_products');
                        checkbox.checked = <?php echo $show_all_products; ?>; // Initialize checkbox state
                        checkbox.addEventListener('change', function() {
                            const showAllProducts = this.checked ? 1 : 0;
                            fetch('update_setting.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({ show_all_products: showAllProducts })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // Optionally show a success message
                                    console.log('Setting updated successfully');
                                } else {
                                    console.error('Failed to update setting:', data.error);
                                    checkbox.checked = !checkbox.checked; // Revert on error
                                    alert('Failed to update setting. Please try again.');
                                }
                            })
                            .catch(error => {
                                console.error('Error updating setting:', error);
                                checkbox.checked = !checkbox.checked; // Revert on error
                                alert('Error updating setting. Please try again.');
                            });
                        });
                    });
                </script>

                    
                
                    <div class="bg-white shadow-xl rounded-xl p-4 lg:p-8 mb-6 lg:mb-8 relative z-10">
                        <h2 class="text-xl lg:text-2xl font-bold mb-4 lg:mb-6">Update Account Details</h2>
                        
                        <form action="" method="POST" class="space-y-6">
                            <?php
                            try {
                                $pdo = new PDO('sqlite:user.db');
                                $stmt = $pdo->prepare("SELECT username, email FROM users WHERE role = 'cashier'");
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
                                        <input type="password" name="current_password" id="current_password" required
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
                                        <input type="password" name="new_password" id="new_password" 
                                            class="block w-full pl-10 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm">
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-8">
                                <button type="submit" name="update_account" class="inline-flex items-center px-4 lg:px-6 py-2 lg:py-3 border border-transparent text-sm lg:text-base font-medium rounded-lg shadow-sm text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 lg:h-5 lg:w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                        <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                                    </svg>
                                    Update Account
                                </button>

                                <a href="damaged_goods" class="inline-flex items-center px-4 lg:px-6 py-2 lg:py-3 border border-gray-300 text-sm lg:text-base font-medium rounded-lg shadow-sm bg-transparent text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    Damaged Stock
                                </a>
                            </div>
                        </form>

                        <?php
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
                            try {
                                $pdo = new PDO('sqlite:user.db');
                                
                                // Verify current password
                                $stmt = $pdo->prepare("SELECT id, password_hash, email FROM users WHERE role = 'cashier'");
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

                                        echo "<div class='mt-4 p-4 bg-teal-100 text-teal-700 rounded fade-in z-20' role='alert'>
                                            Account details updated successfully!
                                            <meta http-equiv='refresh' content='1;URL=settings'>
                                        </div>";
                                    }
                                } else {
                                    echo "<div class='mt-4 p-4 bg-red-100 text-red-700 rounded fade-in z-20' role='alert'>
                                        Current password is incorrect.
                                    </div>";
                                }
                            } catch(PDOException $e) {
                                echo "<div class='mt-4 p-4 bg-red-100 text-red-700 rounded fade-in z-20' role='alert'>
                                    Error updating account details: " . htmlspecialchars($e->getMessage()) . "
                                </div>";
                            }
                        }
                        ?>
                    </div>

                    <div class="bg-white shadow-xl rounded-xl p-4 lg:p-8 mb-6 lg:mb-8 relative z-10">
                    <h2 class="text-xl lg:text-2xl font-bold mb-4 lg:mb-6">Software Activation</h2>
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
                                    class="block w-full pl-8 lg:pl-10 pr-3 py-2 lg:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-600 focus:border-transparent shadow-sm placeholder-gray-400 text-sm lg:text-base">
                            </div>
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 lg:px-6 py-2 lg:py-3 border border-transparent text-sm lg:text-base font-medium rounded-lg shadow-sm text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                            <svg class="w-4 h-4 lg:w-5 lg:h-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>
                            </svg>
                            Activate
                        </button>
                    </form>

                    <?php
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_software']) && isset($_POST['key'])) {
                        $submittedKey = trim($_POST['key']);
                        $csrfToken = $_POST['csrf_token'] ?? '';
                        $result = activateKey($submittedKey, $csrfToken);
                        
                        if ($result['success']) {
                            echo "<div class='mt-4 p-4 bg-teal-100 text-teal-700 rounded fade-in z-20' role='alert'>
                                <strong>Success!</strong> {$result['message']}
                            </div>";
                        } else {
                            echo "<div class='mt-4 p-4 bg-red-100 text-red-700 rounded fade-in z-20' role='alert'>
                                <strong>Error!</strong> {$result['message']}
                            </div>";
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
                        
                        echo "<div class='mt-4 p-4 bg-teal-100 text-teal-700 rounded fade-in z-20' role='alert'>
                            <strong>✓ Your account is activated.</strong>
                            {$expiryMessage}
                        </div>";
                    } elseif ($activationCheck['status'] === 'expired') {
                        echo "<div class='mt-4 p-4 bg-red-100 text-red-700 rounded fade-in z-20' role='alert'>
                            <strong>Activation Expired!</strong> Your activation key has expired on " . date('M d, Y', strtotime($activationCheck['expired_date'])) . ". Please enter a new activation key to continue using the system.
                        </div>";
                    } else {
                        echo "<div class='mt-4 p-4 bg-yellow-100 text-yellow-700 rounded fade-in z-20' role='alert'>
                            <h2 class='font-bold'>Payment Methods</h2>
                            <p>Your account is not activated. Please contact <a href='mailto:info.easystockna@gmail.com' class='text-gray-600 underline'>info.easystockna@gmail.com</a> or call 0814759498 to purchase a key.</p><br>
                            <div class='mt-2 flex space-x-4'>
                                <div class='flex items-center'>
                                    <img src='/props/FNB_Color.png' alt='FNB eWallet' class='h-12 w-12'> <!-- Increased size for visibility -->
                                    <span class='ml-2'>FNB eWallet</span>
                                </div>
                                <div class='flex items-center'>
                                    <img src='/props/1677845410-33-bank-windhoek.png' alt='Bank Windhoek gray Wallet' class='h-12 w-12'> <!-- Increased size for visibility -->
                                    <span class='ml-2'>Bank Windhoek gray Wallet</span>
                                </div>
                                <div class='flex items-center'>
                                    <img src='props/standard-bank-group-logo.png' alt='Standard Bank Easy Wallet' class='h-12 w-12'> <!-- Increased size for visibility -->
                                    <span class='ml-2'>Standard Bank Easy Wallet</span>
                                </div>
                                <div class='flex items-center'>
                                    <img src='props/nedbank-logo-web.jpg' alt='Nedbank' class='h-12 w-12'> <!-- Increased size for visibility -->
                                    <span class='ml-2'>Nedbank</span>
                                </div>
                            </div>
                        </div>";
                    }
                    ?>
                </div>
  

                <!-- Existing delete section -->
                <?php
                if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all'])) {
                    try {
                        // Database connection
                        $db = new PDO('sqlite:pos.db');
                        
                        // Enable foreign key support for SQLite
                        $db->exec('PRAGMA foreign_keys = OFF');
                        
                        // Delete all records from orders and order_items tables
                        $db->exec("DELETE FROM orders");
                        $db->exec("DELETE FROM order_items"); 
                        
                        // Re-enable foreign key support
                        $db->exec('PRAGMA foreign_keys = ON');
                        
                        // Show success message instead of redirecting
                        echo "<div class='mt-4 p-4 bg-teal-100 text-teal-700 rounded fade-in z-20' role='alert'>
                            All records have been deleted successfully.
                        </div>";
                        
                    } catch(PDOException $e) {
                                    echo "<div class='mt-4 p-4 bg-red-100 text-red-700 rounded fade-in z-20' role='alert'>
                                        Error: " . htmlspecialchars($e->getMessage()) . "
                                    </div>";
                    }
                }
                ?>
            </div>
        </div>
    </div>



<script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkbox = document.getElementById('show_all_products');

        checkbox.addEventListener('change', function() {
            const showAllProducts = this.checked ? 1 : 0;

            // Send an AJAX request to update the setting in the database
            fetch('update_setting.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ show_all_products: showAllProducts })
            })
            .then(response => response.json())
            .then(data => {

            })
            .catch(error => {
          

            });
            });
        });
        
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
    </script>
</body>
</html>
