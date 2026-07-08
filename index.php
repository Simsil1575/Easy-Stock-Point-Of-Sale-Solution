<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is already logged in, redirect to appropriate home page
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);
    if ($role === 'admin') {
        header("Location: admin/home");
        exit();
    } elseif ($role === 'manager') {
        header("Location: manager/home");
        exit();
    } elseif ($role === 'waitress') {
        header("Location: waitress/home");
        exit();
    } else {
        header("Location: home");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = md5($_POST['password']); // Use MD5 hashing
    $userType = $_POST['userType'];

    try {
        // Connect to user database using absolute path
        $db_file = realpath(dirname(__FILE__) . '/user.db');
        $userDb = new PDO("sqlite:$db_file");
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Prepare SQL statement to prevent SQL injection
        $stmt = $userDb->prepare("SELECT id, username, password_hash, role FROM users WHERE username = :username AND role = :role");
        $stmt->execute([
            ':username' => $username,
            ':role' => $userType
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Verify the MD5 hashed password
            if ($password === $user['password_hash']) {
                // Connect to POS database using absolute path
                $pos_db_file = realpath(dirname(__FILE__) . '/pos.db');
                $posDb = new PDO("sqlite:$pos_db_file");
                $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Record login in POS database
                $logStmt = $posDb->prepare("INSERT INTO user_log (user_id, action_type) VALUES (:username, 'login')");
                $logStmt->execute([':username' => $user['username']]);
                
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                // Ensure session is written before redirect
                session_write_close();
                
                // Redirect based on role with absolute paths
                if ($user['role'] === 'admin') {
                    header("Location: admin/home");
                } elseif ($user['role'] === 'manager') {
                    header("Location: manager/home");
                } elseif ($user['role'] === 'waitress') {
                    header("Location: waitress/home");
                } else {
                    header("Location: home");
                }
                exit();
            } else {
                $error_message = "Invalid Password!";
                header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?error=" . urlencode($error_message));
                exit();
            }
        } else {
            $error_message = "Invalid Username/Password!!";
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?error=" . urlencode($error_message));
            exit();
        }
    } catch(PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?error=" . urlencode($error_message));
        exit();
    }
}

// Check if there are any waitress accounts in the database
$has_waitress_accounts = false;
try {
    $db_file = realpath(dirname(__FILE__) . '/user.db');
    $userDb = new PDO("sqlite:$db_file");
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $waitressCheckStmt = $userDb->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'waitress'");
    $waitressCheckStmt->execute();
    $waitressResult = $waitressCheckStmt->fetch(PDO::FETCH_ASSOC);
    $has_waitress_accounts = ($waitressResult['count'] > 0);
} catch(PDOException $e) {
    // If database error, default to false (don't show waitress option)
    $has_waitress_accounts = false;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no">
    <title>Easy Stock POS - Namibian Pub & Tuckshop Management Solution</title>
    <meta name="description" content="Streamline your Namibian pub, lounge, bar, tuckshop, or festival stall with Easy Stock's Point of Sale software. Manage inventory, process sales, and boost your business performance.">
    <meta property="og:title" content="Easy Stock POS - Namibian Pub & Tuckshop Management Solution">
    <meta property="og:description" content="Streamline your Namibian pub, lounge, bar, tuckshop, or festival stall with Easy Stock's Point of Sale software. Manage inventory, process sales, and boost your business performance.">
    <meta property="og:image" content="https://example.com/tate-sebby-pos-og-image.jpg">
    <meta property="og:url" content="https://www.tatesebbypos.com">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta charset="UTF-8">
    <title>POS Solution</title>
    <script src="navigation.js" async></script>
    <script src="src/howler.min.js"></script>
    <script src="src/chart.js"></script>
    <script src="admin/inbox.js" defer></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="logo.png" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="3.4.16"></script>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "SoftwareApplication",
      "name": "Easy Stock POS",
      "applicationCategory": "BusinessApplication",
      "operatingSystem": "All",
      "offers": {
        "@type": "Offer",
        "price": "499.99",
        "priceCurrency": "NAD"
      },
      "description": "Point of Sale software for Namibian pubs, lounges, bars, tuckshops, and festival stalls."
    }
    </script>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="description" content="Point of Sale Solution">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="POS Solution">
    
    <!-- PWA Icons -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="/icon512_rounded.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/icon512_rounded.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/icon512_rounded.png">
    <link rel="apple-touch-icon" sizes="167x167" href="/icon512_rounded.png">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json" type="application/manifest+json">
    
    <!-- PWA Service Worker Registration and Install Prompt Script -->
    <script>
        // Global variable to store the deferred prompt
        let deferredPrompt = null;
        let isInstalled = false;

        // Detect if app is already running in standalone / installed mode
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
            isInstalled = true;
            console.log('[PWA] App is already installed');
        }

        // Register Service Worker for all modes (browser + installed) - required for installability
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/service-worker.js', { scope: '/' })
                    .then(registration => {
                        console.log('[PWA] Service Worker registered:', registration.scope);

                        // Wait for service worker to be activated
                        if (registration.installing) {
                            registration.installing.addEventListener('statechange', function() {
                                if (this.state === 'activated') {
                                    console.log('[PWA] Service Worker activated');
                                    registration.update();
                                }
                            });
                        } else if (registration.waiting) {
                            registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                        } else if (registration.active) {
                            console.log('[PWA] Service Worker already active');
                            if (registration.active.state === 'activated') {
                                console.log('[PWA] Service Worker is controlling the page');
                            }
                        }

                        // Listen for updates
                        registration.addEventListener('updatefound', () => {
                            const newWorker = registration.installing;
                            if (!newWorker) return;
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'activated') {
                                    console.log('[PWA] New Service Worker activated');
                                }
                            });
                        });

                        // Periodic update check
                        setInterval(() => {
                            registration.update();
                        }, 60000);
                    })
                    .catch(error => {
                        console.error('[PWA] Service Worker registration failed:', error);
                    });
            });
        }

        // Handle beforeinstallprompt event - Android Chrome install prompt
        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('[PWA] beforeinstallprompt event fired');
            // Prevent the mini-infobar from appearing (per https://web.dev/articles/customize-install#criteria)
            e.preventDefault();
            // Stash the event so it can be triggered later
            deferredPrompt = e;

            console.log('[PWA] PWA can be installed - prompt available');

            // Show custom install button using Tailwind-styled UI
            const installBtn = document.getElementById('pwaInstallButton');
            if (installBtn) {
                installBtn.classList.remove('hidden');
                installBtn.classList.add('inline-flex');
            }
        });

        // Handle app installed event
        window.addEventListener('appinstalled', () => {
            console.log('[PWA] PWA was installed');
            deferredPrompt = null;
            isInstalled = true;

            const installBtn = document.getElementById('pwaInstallButton');
            if (installBtn) {
                installBtn.classList.add('hidden');
            }
        });

        // Function to trigger install prompt programmatically for the custom button
        function installPWA() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('[PWA] User accepted the install prompt');
                    } else {
                        console.log('[PWA] User dismissed the install prompt');
                    }
                    deferredPrompt = null;

                    const installBtn = document.getElementById('pwaInstallButton');
                    if (installBtn) {
                        installBtn.classList.add('hidden');
                    }
                });
            } else {
                console.log('[PWA] Install prompt not available');
            }
        }

        // Expose install function globally for the inline button handler
        window.installPWA = installPWA;
    </script>
    
    <style>

    
        body {
            -webkit-user-select: none; /* Safari */
            -ms-user-select: none; /* IE 10 and IE 11 */
            user-select: none; /* Standard syntax */
        }
        img {
            -webkit-user-drag: none;
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
            user-drag: none;
        }
    </style>
</head>
<body class="font-sans bg-gray-100">
    <!-- Inbox Notification Container -->
    <div id="inbox-notification" class="fixed bottom-4 right-4 z-50 hidden">
        <div class="rounded-lg bg-white p-4 shadow-lg ring-1 ring-slate-200 max-w-sm">
            <div class="flex items-start gap-3">
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10">
                    <i data-lucide="message-square" class="h-4 w-4 text-primary"></i>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-slate-900">New AI Insight</p>
                    <p class="text-sm text-slate-500 inbox-message"></p>
                    <div class="mt-2 flex items-center gap-2">
                        <a href="admin/inbox" class="text-xs text-primary hover:text-primary/80">View in Inbox</a>
                        <button class="text-xs text-slate-400 hover:text-slate-500 close-notification">Dismiss</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <main>
        <section id="hero" class="relative text-black">
            <div class="absolute inset-0 z-0">
  
            </div>
            
            <header class="relative z-80">
                <nav class="container mx-auto px-8 py-3 flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="h-16 w-auto md:h-20 lg:h-24"></div>
                    </div>
                    <div class="hidden md:flex items-center space-x-4">
                        <a href="#features" class="text-sm font-medium text-gray-700 hover:text-gray-900">Features</a>
                        <a href="#pricing" class="text-sm font-medium text-gray-700 hover:text-gray-900">Pricing</a>
                        <a href="#contact" class="text-sm font-medium text-gray-700 hover:text-gray-900">Requirements</a>
                        <a href="#cta" class="text-sm font-medium text-gray-700 hover:text-gray-900">Contact</a>

                        <!-- Tailwind-styled PWA install button (shown only when installable) -->
                        <button
                            id="pwaInstallButton"
                            type="button"
                            onclick="window.installPWA()"
                            class="hidden ml-4 inline-flex items-center gap-2 rounded-full bg-teal-600 px-4 py-1.5 text-sm font-semibold text-white shadow-sm ring-1 ring-teal-500/60 hover:bg-teal-500 hover:ring-teal-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-teal-500 transition"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 3v10m0 0-3.5-3.5M10 13l3.5-3.5M4 16h12" />
                            </svg>
                            <span>Install app</span>
                        </button>
                    </div>
                    <div class="md:hidden">
                        <button id="menu-toggle" class="text-gray-700 focus:outline-none">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
                            </svg>
                        </button>
                    </div>
                </nav>
                <div id="mobile-menu" class="hidden md:hidden">
                    <a href="#features" class="block py-2 px-4 text-sm hover:bg-gray-700">Features</a>
                    <a href="#pricing" class="block py-2 px-4 text-sm hover:bg-gray-700">Pricing</a>
                    <a href="#contact" class="block py-2 px-4 text-sm hover:bg-gray-700">Requirements</a>
                    <a href="#cta" class="block py-2 px-4 text-sm hover:bg-gray-700">Contact</a>
                </div>
            </header>
<div class="container mx-auto px-2 sm:px-4 lg:px-6 relative z-10 flex justify-center py-10 sm:py-17">
    <div class="max-w-full sm:max-w-7xl mx-auto">
                    <div class="flex flex-col md:flex-row items-center justify-center md:space-x-8">
                    <div class="w-full md:w-1/3 text-center md:text-left">
    <h1 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold mb-4 leading-tight text-gray-600">
        <i class="fa-solid fa-cube fa-bounce"></i>
    </h1>

    <h1 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold mb-4 leading-tight text-gray-600">
        Easy Stock Management System
    </h1>

    <p class="text-base sm:text-lg mb-8 max-w-xl text-gray-700">
        Start your business today! Our stock management system helps pubs, lounges, bars, tuckshops, and festival stalls succeed.
    </p>

    <div class="hidden md:flex items-center gap-4 mt-10 mb-4">
       
        
        <?php
        // Get server IP address
        $serverIP = $_SERVER['SERVER_ADDR'] ?? 'localhost';
        // If SERVER_ADDR is localhost or 127.0.0.1, try to get the actual IP
        if ($serverIP === '127.0.0.1' || $serverIP === '::1' || empty($serverIP)) {
            // Try to get the actual network IP
            $hostname = gethostname();
            $serverIP = gethostbyname($hostname);
            // If still localhost, use HTTP_HOST
            if ($serverIP === '127.0.0.1' || $serverIP === $hostname) {
                $serverIP = $_SERVER['HTTP_HOST'] ?? 'localhost';
            }
        }
        
        // Construct the URL (use http:// if not HTTPS)
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $serverURL = $protocol . $serverIP;
        
        // Generate QR code URL using free QR code API
        $qrCodeURL = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($serverURL);
        ?>
        
        <div class="flex flex-col items-center">
            <img src="<?php echo htmlspecialchars($qrCodeURL); ?>" 
                 alt="QR Code - <?php echo htmlspecialchars($serverURL); ?>" 
                 class="w-24 h-24 border-2 border-gray-300 rounded-lg p-1 bg-white shadow-sm">
            <p class="text-xs text-gray-500 mt-1 break-all max-w-[120px]"><?php echo htmlspecialchars($serverURL); ?></p>
        </div> 
        <span class="inline-block bg-teal-300 text-teal-900 font-semibold px-3 py-1 rounded shadow text-xs uppercase tracking-wider">
            <i class="fas fa-cube mr-1 text-teal-600"></i> Version 10
        </span>
    </div>
</div>

                        <!-- Login Form Section -->
                        <div class="flex flex-col justify-center w-full md:w-1/2 px-2 sm:px-4 lg:px-8">
    
                    
                            <div class="mt-10 sm:mx-auto sm:w-full sm:max-w-sm bg-gray-250 rounded-xl p-2">
                    
                    
                                <div class="mb-8 grid grid-cols-2 sm:flex sm:justify-center gap-2 sm:gap-4 sm:space-x-0">
                                    <?php if ($has_waitress_accounts): ?>
                                    <button id="waitressBtn" type="button" class="user-role-btn w-full sm:w-28 px-2 sm:px-3 py-2 sm:py-1.5 flex items-center justify-between border-2 border-transparent bg-white rounded-xl shadow-md hover:shadow-lg scale-100 transition-all duration-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-circle text-base sm:text-xl text-gray-500 mr-1 sm:mr-2"></i>
                                            <span class="text-xs font-semibold text-gray-700">Waitress</span>
                                        </div>
                                        <div class="checkmark opacity-0 transition-opacity duration-100">
                                            <svg class="w-3 h-3 sm:w-4 sm:h-4 text-teal-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </div>
                                    </button>
                                    <?php endif; ?>
                                    <button id="cashierBtn" type="button" class="user-role-btn w-full sm:w-28 px-2 sm:px-3 py-2 sm:py-1.5 flex items-center justify-between border-2 border-transparent bg-white rounded-xl shadow-md hover:shadow-lg scale-100 transition-all duration-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-tag text-base sm:text-xl text-gray-500 mr-1 sm:mr-2"></i>
                                            <span class="text-xs font-semibold text-gray-700">Cashier</span>
                                        </div>
                                        <div class="checkmark opacity-0 transition-opacity duration-100">
                                            <svg class="w-3 h-3 sm:w-4 sm:h-4 text-teal-500" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </div>
                                    </button>
                                    <button id="managerBtn" type="button" class="user-role-btn w-full sm:w-28 px-2 sm:px-3 py-2 sm:py-1.5 flex items-center justify-between border-2 border-transparent bg-white rounded-xl shadow-md hover:shadow-lg scale-100 transition-all duration-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-tie text-base sm:text-xl text-gray-600 mr-1 sm:mr-2"></i>
                                            <span class="text-xs font-semibold text-gray-700">Manager</span>
                                        </div>
                                        <div class="checkmark opacity-0 transition-opacity duration-100">
                                            <svg class="w-3 h-3 sm:w-4 sm:h-4 text-teal-700" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </div>
                                    </button>
                                    <button id="adminBtn" type="button" class="user-role-btn w-full sm:w-28 px-2 sm:px-3 py-2 sm:py-1.5 flex items-center justify-between border-2 border-transparent bg-white rounded-xl shadow-md hover:shadow-lg scale-100 transition-all duration-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-shield text-base sm:text-xl text-gray-700 mr-1 sm:mr-2"></i>
                                            <span class="text-xs font-semibold text-gray-700">Admin</span>
                                        </div>
                                        <div class="checkmark opacity-0 transition-opacity duration-100">
                                            <svg class="w-3 h-3 sm:w-4 sm:h-4 text-teal-900" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </div>
                                    </button>
                                </div>
                                <?php if (isset($_GET['error'])): ?>
                                    <div id="errorAlert" class="mb-4 bg-red-600 text-white px-4 py-3 rounded-lg flex items-center" role="alert">
                                        <i class="fas fa-exclamation-triangle mr-3"></i>
                                        <span class="flex-1"><?php echo htmlspecialchars($_GET['error']); ?></span>
                                        <i class="fas fa-times cursor-pointer" onclick="document.getElementById('errorAlert').style.display='none'"></i>
                                    </div>
                                <?php endif; ?>
                                <form class="space-y-6" method="POST" action="<?php echo htmlspecialchars(strtok($_SERVER["REQUEST_URI"], '?')); ?>">
                                    <div>
                                        <label for="username" class="block text-sm/6 font-medium text-black">Username</label>
                                        <div class="mt-2 relative">
                                            <input type="text" id="username" name="username" required autocomplete="username"
                                                   class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-base text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200 sm:text-sm/6">
                                            <i class="fas fa-user absolute left-3 top-2.5 text-gray-400"></i>
                                        </div>
                                    </div>
                    
                                    <div>
                                        <div class="flex items-center justify-between">
                                            <label for="password" class="block text-sm/6 font-medium text-black">Password</label>
                                        </div>
                                        <div class="mt-2 relative">
                                            <input type="password" id="password" name="password" required autocomplete="current-password"
                                                   class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-base text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200 sm:text-sm/6">
                                            <i class="fas fa-lock absolute left-3 top-2.5 text-gray-400"></i>
                                        </div>
                                    </div>
                                    <div class="mt-4 text-left">
                                        <a href="resetpass/requestReset" class="text-sm text-gray-600 hover:text-gray-900">Forgot your password?</a>
                                    </div>
                    
                                    <input type="hidden" id="userType" name="userType" value="<?php echo $has_waitress_accounts ? 'waitress' : 'cashier'; ?>">
                    
                                    <button type="submit" 
                                            class="flex w-full items-center justify-center rounded-lg border-2 border-gray-400 bg-transparent px-4 py-2.5 text-sm/6 font-semibold text-gray-700 hover:border-gray-600 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-300 transition duration-200">
                                        <i class="fas fa-sign-in-alt mr-2"></i>Sign in
                                    </button>
                    
                    
                    
                                    
                                </form>
                    
                            <div class="mt-8 text-center text-sm text-gray-500">
                                <p>&copy; <?php echo date('Y'); ?> Easy Stock POS Solutions. All rights reserved.</p>
                            </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>


    

    <script>
        // Execute code as soon as possible without waiting for DOMContentLoaded
        (function() {
            const waitressBtn = document.getElementById('waitressBtn');
            const cashierBtn = document.getElementById('cashierBtn');
            const managerBtn = document.getElementById('managerBtn');
            const adminBtn = document.getElementById('adminBtn');
            const userType = document.getElementById('userType');
            
            // Build buttons array, excluding null elements (waitressBtn might not exist)
            const buttons = [waitressBtn, cashierBtn, managerBtn, adminBtn].filter(btn => btn !== null);

            if (!cashierBtn || !managerBtn || !adminBtn || !userType) return;

            // CSS classes for transition states
            const baseClasses = "user-role-btn w-full sm:w-28 px-2 sm:px-3 py-2 sm:py-1.5 flex items-center justify-between border-2 rounded-xl shadow-md hover:shadow-lg transition-all duration-100";
            const activeClasses = {
                waitress: "border-teal-400 bg-teal-50 scale-105",
                cashier: "border-teal-500 bg-teal-50 scale-105",
                manager: "border-teal-700 bg-teal-100 scale-105",
                admin: "border-teal-900 bg-teal-200 scale-105"
            };
            const inactiveClasses = "border-transparent bg-white scale-100";

            function selectButton(selectedBtn, role) {
                // Update userType value immediately
                userType.value = role;
                
                // Optimize button updates by only changing what's necessary
                buttons.forEach(btn => {
                    const checkmark = btn.querySelector('.checkmark');
                    const isActive = btn === selectedBtn;
                    
                    // Only update if state changed
                    if ((checkmark.style.opacity === '1') !== isActive) {
                        checkmark.style.opacity = isActive ? '1' : '0';
                    }
                    
                    // Update only buttons that need to change
                    const shouldBeActive = isActive;
                    const isCurrentlyActive = btn.classList.contains('scale-105');
                    
                    if (shouldBeActive !== isCurrentlyActive) {
                        if (shouldBeActive) {
                            btn.className = `${baseClasses} ${activeClasses[role]}`;
                        } else {
                            btn.className = `${baseClasses} ${inactiveClasses}`;
                        }
                    }
                });
            }

            // Set initial state - use waitress if available, otherwise cashier
            if (waitressBtn) {
                selectButton(waitressBtn, 'waitress');
            } else {
                selectButton(cashierBtn, 'cashier');
            }

            // Use event delegation for better performance
            if (waitressBtn) {
                waitressBtn.onclick = () => selectButton(waitressBtn, 'waitress');
            }
            cashierBtn.onclick = () => selectButton(cashierBtn, 'cashier');
            managerBtn.onclick = () => selectButton(managerBtn, 'manager');
            adminBtn.onclick = () => selectButton(adminBtn, 'admin');
        })();

        // Mobile menu toggle
        document.getElementById('menu-toggle')?.addEventListener('click', function() {
            document.getElementById('mobile-menu')?.classList.toggle('hidden');
        });

        // Smooth scroll for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const targetElement = document.querySelector(this.getAttribute('href'));
                if (targetElement) {
                    targetElement.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Add animation on scroll
        function reveal() {
            var reveals = document.querySelectorAll(".reveal");
            for (var i = 0; i < reveals.length; i++) {
                var windowHeight = window.innerHeight;
                var elementTop = reveals[i].getBoundingClientRect().top;
                var elementVisible = 150;
                if (elementTop < windowHeight - elementVisible) {
                    reveals[i].classList.add("active");
                } else {
                    reveals[i].classList.remove("active");
                }
            }
        }

        window.addEventListener("scroll", reveal);
        reveal(); // Call on page load


    </script>

</body>
</html>
<script src="admin/3.4.16" async defer></script>
