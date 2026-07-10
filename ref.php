<?php

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = md5($_POST['password']); // Using MD5 hashing
    $userType = $_POST['userType'];

    try {
        $db = new PDO('sqlite:user.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Prepare SQL statement to prevent SQL injection
        $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? AND role = ?");
        $stmt->execute([$username, $userType]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $password === $user['password_hash']) {
            // Start a new session to clear any old data
            session_regenerate_id(true);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username']; 
            $_SESSION['role'] = $user['role'];
            
            // Debug line to verify session data (remove in production)
            error_log("Session data: " . print_r($_SESSION, true));
            
            // Ensure session data is written
            session_write_close();
            
            // Redirect based on role with absolute paths
            if ($user['role'] === 'admin') {
                header("Location: /admin/home");
            } else {
                header("Location: /home");
            }
            exit();
        } else {
            $error_message = "Invalid Username or Password!";
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?error=" . urlencode($error_message));
            exit();
        }
    } catch(PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?error=" . urlencode($error_message));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Easy Stock POS - Namibian Pub & Tuckshop Management Solution</title>
    <meta name="description" content="Streamline your Namibian pub, lounge, bar, tuckshop, or festival stall with Easy Stock's Point of Sale software. Manage inventory, process sales, and boost your business performance.">
    <meta property="og:title" content="Easy Stock POS - Namibian Pub & Tuckshop Management Solution">
    <meta property="og:description" content="Streamline your Namibian pub, lounge, bar, tuckshop, or festival stall with Easy Stock's Point of Sale software. Manage inventory, process sales, and boost your business performance.">
    <meta property="og:image" content="https://example.com/tate-sebby-pos-og-image.jpg">
    <meta property="og:url" content="https://www.tatesebbypos.com">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Solution</title>
    <link href="src/output.css" rel="stylesheet">
    <script src="navigation.js" async></script>
    <script src="src/howler.min.js"></script>
    <script src="src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="logo.png" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="tailwind.16"></script>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
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
    <main>
        <section id="hero" class="relative text-black">
            <div class="absolute inset-0 z-0">
  
            </div>
            
            <header class="relative z-50">
                <nav class="container mx-auto px-6 py-3 flex justify-between items-center">
                    <div class="flex items-center">
                        <img src="https://hebbkx1anhila5yf.public.blob.vercel-storage.com/logo-jDJs1WDcImng9gw325LFerJlFjJFk1.png" alt="Easy Stock Logo" class="h-16 w-auto md:h-20 lg:h-24">
                    </div>
                    <div class="hidden md:flex space-x-4">
                        <a href="#features" class="hover:text-gray-300">Features</a>
                        <a href="#pricing" class="hover:text-gray-300">Pricing</a>
                        <a href="#contact" class="hover:text-gray-300">Requirements</a>
                        <a href="#cta" class="hover:text-gray-300">Contact</a>
                    </div>
                    <div class="md:hidden">
                        <button id="menu-toggle" class="text-white focus:outline-none">
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
            <div class="container mx-auto px-4 sm:px-6 relative z-10 flex justify-center py-20">
                <div class="max-w-7xl mx-auto">
                    <div class="flex flex-col md:flex-row items-center justify-center md:space-x-8">
                        <div class="w-full md:w-1/2 text-center md:text-left">
                        <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold mb-4 leading-tight"><i class="fa-solid fa-cash-register fa-bounce"></i></h1>

                            <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold mb-4 leading-tight">SME Stock Management System</h1>
                            <p class="text-lg sm:text-xl mb-8 max-w-2xl">Start your business today! Our stock management system helps pubs, lounges, bars, tuckshops, and festival stalls succeed.</p>
                            <div class="flex flex-col md:flex-row items-center md:items-start">
                                <div class="flex flex-col items-center">
                             
                                    <p class="text-sm text-gray-500 lg:text-6xl mt-10">Offline Version 3.1.0</p>
                                </div>
                            </div>
                        </div>
                        <!-- Login Form Section -->
                        <div class="flex flex-col justify-center px-6  lg:w-1/2 lg:px-8">
    
                    
                            <div class="mt-10 sm:mx-auto sm:w-full sm:max-w-sm bg-gray-250 rounded-xl p-2">
                    
                    
                                <div class="mb-8 flex justify-center space-x-4">
                                    <button id="cashierBtn" class="px-6 py-2.5 bg-gray-300 text-black font-semibold rounded-lg shadow-md hover:bg-gray-200 transition duration-200">
                                        <i class="fas fa-user-tag mr-2"></i>Cashier
                                    </button>
                                    <button id="adminBtn" class="px-6 py-2.5 bg-gray-200 text-black font-semibold rounded-lg shadow-md hover:bg-gray-200 transition duration-200">
                                        <i class="fas fa-user-shield mr-2"></i>Admin
                                    </button>
                                </div>
                                <?php if (isset($_GET['error'])): ?>
                                    <div id="errorAlert" class="mb-4 bg-red-600 text-white px-4 py-3 rounded-lg flex items-center" role="alert">
                                        <i class="fas fa-exclamation-triangle mr-3"></i>
                                        <span class="flex-1"><?php echo htmlspecialchars($_GET['error']); ?></span>
                                        <i class="fas fa-times cursor-pointer" onclick="document.getElementById('errorAlert').style.display='none'"></i>
                                    </div>
                                <?php endif; ?>
                                <form class="space-y-6" method="POST" action="<?php echo htmlspecialchars(strtok($_SERVER["REQUEST_URI"], '?')); ?>" autocomplete="off">
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
                                            <input type="password" id="password" name="password" required autocomplete="off"
                                                   class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-base text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200 sm:text-sm/6">
                                            <i class="fas fa-lock absolute left-3 top-2.5 text-gray-400"></i>
                                        </div>
                                    </div>
                                    <div class="mt-4 text-left">
                                        <a href="resetpass/requestReset" class="text-sm text-gray-600 hover:text-gray-900">Forgot your password?</a>
                                    </div>
                    
                                    <input type="hidden" id="userType" name="userType" value="cashier">
                    
                                    <button type="submit" 
                                            class="flex w-full justify-center rounded-lg bg-gray-300 px-4 py-2.5 text-sm/6 font-semibold text-black shadow-md hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-300 transition duration-200">
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


              <section id="features" class="py-20">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <h2 class="text-3xl font-bold text-center mb-12">Powerful Features for Namibian Businesses</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="bg-white rounded-lg shadow-md p-6 text-center">
                        <img src="props/Macbook-Air-localhost (17).png" alt="Product 1" class="w-full h-auto mb-4 rounded-lg">
                        <h3 class="text-xl font-semibold mb-2">Easy Inventory Management</h3>
                        <p class="text-gray-600">Keep track of your stock levels and get low inventory alerts.</p>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 text-center">
                        <img src="props/getimg_ai_img-UO9ENdvS6d812LVQbzrEo (1).png" alt="Product 2" class="w-60 h-auto mb-4 rounded-lg mx-auto">
                        <h3 class="text-xl font-semibold mb-2">Easy Solutions for Tuckshops and Home Businesses</h3>
                        <p class="text-gray-600">Make your sales and stock management simple and effective.</p>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 text-center">
                        <img src="props/Macbook-Air-localhost (21).png" alt="Product 3" class="w-full h-auto mb-4 rounded-lg">
                        <h3 class="text-xl font-semibold mb-2">Insightful Reports</h3>
                        <p class="text-gray-600">Get detailed analytics to make informed business decisions.</p>
                    </div>
                </div>
                <div class="mt-12 flex flex-col md:flex-row justify-center items-center">
                    <div class="w-full md:w-1/2 pr-0 md:pr-8 text-center md:text-left mb-8 md:mb-0">
                        <h3 class="text-2xl font-bold mb-4">See Our Software in Action!</h3>
                        <p class="text-gray-600">Watch how our powerful POS system streamlines your business operations. From quick sales to detailed reporting, experience the future of retail management in Namibia.</p>
                        <ul class="mt-4 space-y-2">
                            <li class="flex items-center justify-center md:justify-start">
                                <svg class="w-5 h-5 mr-2 text-teal-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                                Easy to learn and use
                            </li>
                            <li class="flex items-center justify-center md:justify-start">
                                <svg class="w-5 h-5 mr-2 text-teal-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                                Built for Namibian businesses
                            </li>
                        </ul>
                    </div>
                    <div class="w-full md:w-1/2 flex justify-center">
                        <iframe width="515" height="309" src="https://www.youtube.com/embed/2d4FHzc2BN0?si=egswyddZUdLoYguu" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    </div>
                </div>
            </div>
        </section>

        <section id="pricing" class="bg-gradient-to-b from-gray-900 to-gray-800 py-24">
            <div class="container mx-auto px-6">
                <h2 class="text-4xl font-extrabold text-center mb-4 text-white">Pricing Plans</h2>
                <p class="text-gray-400 text-center mb-12 text-lg">Choose the perfect plan for your business needs</p>
                <div class="flex flex-wrap justify-center gap-8">



                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-400 rounded-2xl shadow-2xl p-8 w-full md:w-1/3 transform hover:scale-105 transition-all duration-300 relative">

                        <div class="flex items-center justify-between mb-8">
                            <h3 class="text-2xl font-bold text-gray-900">Demo Activation</h3>
                            <span class="p-2 bg-gray-900 rounded-full">
                                <svg class="w-6 h-6 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/>
                                </svg>
                            </span>
                        </div>
                        <p class="text-5xl font-black mb-6 text-gray-900">Free Trial<span class="text-lg font-normal text-gray-700"></span></p>
                        <ul class="mb-8 space-y-4">
                            <li class="flex items-center text-gray-800">
                                <svg class="w-5 h-5 mr-3 text-gray-900" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd"/>
                                </svg>
                                3 Days Free Trial
                            </li>
                            <li class="flex items-center text-gray-800">
                                <svg class="w-5 h-5 mr-3 text-gray-900" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd"/>
                                </svg>
                                Limited Features
                            </li>
                            <li class="flex items-center text-gray-800">
                                <svg class="w-5 h-5 mr-3 text-gray-900" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd"/>
                                </svg>
                                Activation Required
                            </li>
                        </ul>
                        <a href="StockManagementDemov1.3.zip" download class="block text-center bg-gray-900 text-yellow-500 font-bold py-4 px-6 rounded-xl hover:bg-gray-800 transition duration-300 transform hover:-translate-y-1">
                            <span class="flex items-center justify-center">
                                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 3.5a.5.5 0 0 1 .5.5v7.793l2.146-2.147a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 1 1 .708-.708L9.5 11.793V4a.5.5 0 0 1 .5-.5z"/>
                                </svg>
                                Download
                            </span>
                        </a>
                    </div>



                    <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl shadow-2xl p-8 w-full md:w-1/3 border border-gray-700 transform hover:scale-105 transition-all duration-300">
                        <div class="absolute -top-4 right-4">
                            <span class="bg-red-500 text-white text-sm font-bold px-4 py-1 rounded-full shadow-lg">MOST POPULAR</span>
                        </div>
                        <div class="flex items-center justify-between mb-8">
                            <h3 class="text-2xl font-bold text-white">Activation Fee</h3>
                            <span class="p-2 bg-yellow-500 rounded-full">
                                <svg class="w-6 h-6 text-gray-900" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/>
                                </svg>
                            </span>
                        </div>
                        <p class="text-5xl font-black mb-6 text-white">N$1500<span class="text-lg font-normal text-gray-400"></span></p>
                        <ul class="mb-8 space-y-4">
                            <li class="flex items-center text-gray-300">
                                <svg class="w-5 h-5 mr-3 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd"/>
                                </svg>
                                Complete software included
                            </li>
                            <li class="flex items-center text-gray-300">
                                <svg class="w-5 h-5 mr-3 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd"/>
                                </svg>
                                Unlimited transactions
                            </li>
                            <li class="flex items-center text-gray-300">
                                <svg class="w-5 h-5 mr-3 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd"/>
                                </svg>
                                Tutorial Included                                                        </li>
                        </ul>
                        <a href="#" class="block text-center bg-gradient-to-r from-yellow-500 to-yellow-400 text-gray-900 font-bold py-4 px-6 rounded-xl hover:from-yellow-400 hover:to-yellow-300 transition duration-300 transform hover:-translate-y-1">Contact Us</a>
                    </div>

                </div>
            </div>
        </section>



        <section id="installation" class="py-20 bg-gray-50">
            <div class="container mx-auto px-6">
                <h2 class="text-3xl font-bold text-center mb-12"><i class="fas fa-cog"></i> How to Install</h2>
                <div class="max-w-6xl mx-auto">
                    <div class=" rounded-lg  p-8">

                        <div class="col-span-3 flex justify-center">
                            <iframe width="515" height="309" src="https://www.youtube.com/embed/fKj3Zb5x2hs?start=43" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                        </div><br><br><br>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                            <div class="flex flex-col items-center text-center p-6 border rounded-lg hover:shadow-lg transition-shadow">
                                <img src="ampps_large.png" alt="AMPPS Logo" class="w-12 h-12 mb-4">
                                <h3 class="font-semibold mb-2">1. Install AMPPS</h3>
                                <p class="text-gray-600">Download AMPPS from <a href="https://ampps.com/downloads/" class="text-blue-600 hover:underline">ampps.com/downloads</a> and run the installer</p>
                            </div>

                            <div class="flex flex-col items-center text-center p-6 border rounded-lg hover:shadow-lg transition-shadow">
                                <svg class="w-12 h-12 text-teal-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <h3 class="font-semibold mb-2">2. Start Services</h3>
                                <p class="text-gray-600">Launch AMPPS and ensure Apache & MySQL are running</p>
                            </div>

                            <div class="flex flex-col items-center text-center p-6 border rounded-lg hover:shadow-lg transition-shadow">
                                <svg class="w-12 h-12 text-purple-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                <h3 class="font-semibold mb-2">3. Copy & Configure</h3>
                                <p class="text-gray-600">Copy software files to www folder and set up database</p>
                            </div>                                
                            
                    
                        </div>
                 


                        

                        <div class="mt-8 p-4 bg-blue-50 rounded-lg text-center">
                            <p class="text-blue-800">Need help? Our team provides professional installation services!</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="cta" class="bg-gray-800 text-white py-20">
            <div class="container mx-auto px-6 text-center">
                <h2 class="text-3xl font-bold mb-4">Professional Installation and Setup Services</h2>
                <p class="text-xl mb-8">Our IT team provides expert installation and setup services in Oshakati, Ongwediva, and surrounding areas. We ensure a seamless process, from system installation to staff training.</p>
                <div class="flex flex-col items-center space-y-4">
                    <p class="text-gray-400">Professional setup • Staff training • Ongoing support</p>
                </div>
                <div class="mt-8">
                    <img src="props/selma.jpg" alt="IT Specialist Selma" class="w-48 h-48 rounded-full mx-auto mb-4">
                    <p class="text-xl text-center">For services in the Walvis Bay area, contact Selma</p>
                    <a href="tel:+264816842703" class="bg-yellow-500 text-gray-900 font-bold py-2 px-5 rounded-full text-lg hover:bg-yellow-400 transition duration-300 flex items-center justify-center mt-4 mx-auto" style="padding: 0.618em 1em; width: fit-content;">
                        <i class="fas fa-phone mr-2"></i>
                        Contact Selma
                    </a>
                </div>
            </div>
        </section>



        <section id="locations" class="py-20">
            <div class="container mx-auto px-6">
                <h2 class="text-3xl font-bold text-center mb-12">Our Service Locations</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h3 class="text-xl font-bold mb-4">Ongwediva & Oshakati</h3>
                        <div class="aspect-w-16 aspect-h-9 mb-4">
                            <iframe 
                                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d30374.071452048882!2d15.735138!3d-17.785833!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x1c76f3693e06c8ef%3A0x1ea4f88512b8d557!2sOngwediva!5e0!3m2!1sen!2sna!4v1650000000000!5m2!1sen!2sna"
                                width="100%" 
                                height="300" 
                                style="border:0;" 
                                allowfullscreen="" 
                                loading="lazy"
                                class="rounded-lg">
                            </iframe>
                        </div>
                        <p class="text-gray-600">Main Street, Ongwediva</p>
                        <p class="text-gray-600">Independence Avenue, Oshakati</p>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h3 class="text-xl font-bold mb-4">Walvis Bay</h3>
                        <div class="aspect-w-16 aspect-h-9 mb-4">
                            <iframe 
                                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d15184.035726024441!2d14.505277!3d-22.9575!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x1c76f3693e06c8ef%3A0x1ea4f88512b8d557!2sWalvis%20Bay!5e0!3m2!1sen!2sna!4v1650000000000!5m2!1sen!2sna"
                                width="100%" 
                                height="300" 
                                style="border:0;" 
                                allowfullscreen="" 
                                loading="lazy"
                                class="rounded-lg">
                            </iframe>
                        </div>
                        <p class="text-gray-600">Atlantic Street, Walvis Bay</p>
                    </div>
                </div>
            </div>
        </section>


        <section id="registered" class="bg-gray-800 text-white py-20">
            <div class="container mx-auto px-6 text-center">
                <h2 class="text-3xl font-bold mb-4">Registered with BIPA</h2>
                <p class="text-xl mb-8">We are officially registered with the Business and Intellectual Property Authority of Namibia</p>
                <img src="https://www.bipa.na/wp-content/uploads/2024/08/BIPA-Linear-logo-CMYK_Light-orange-Rich-black-on-White.png" alt="BIPA Logo" class="h-24 mx-auto">
            </div>
        </section>
    </main>

    <footer class="bg-gray-800 text-white py-8">
        <div class="container mx-auto px-6">
            <div class="flex flex-wrap justify-between items-center">
                <div class="w-full md:w-1/3 text-center md:text-left">
                    <img src="https://hebbkx1anhila5yf.public.blob.vercel-storage.com/logo-jDJs1WDcImng9gw325LFerJlFjJFk1.png" alt="Easy Stock Logo" class="h-12 w-auto mx-auto md:mx-0">
                </div>
                <div class="w-full md:w-1/3 text-center mt-4 md:mt-0">
                    <p>&copy; 2024 STSCC Solutions. All rights reserved.</p>
                </div>
                <div class="w-full md:w-1/3 text-center md:text-right mt-4 md:mt-0">
                    <a href="#" class="text-gray-400 hover:text-white mx-2">Privacy Policy</a>
                    <a href="#" class="text-gray-400 hover:text-white mx-2">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        document.getElementById('menu-toggle').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });

        // Smooth scroll for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
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

    <script>
    const cashierBtn = document.getElementById('cashierBtn');
    const adminBtn = document.getElementById('adminBtn');
    const userType = document.getElementById('userType');

    // Set initial selected state
    cashierBtn.classList.add('bg-gray-300');
    adminBtn.classList.add('bg-gray-200');

    cashierBtn.addEventListener('click', () => {
        cashierBtn.classList.remove('bg-gray-200');
        cashierBtn.classList.add('bg-gray-300');
        adminBtn.classList.remove('bg-gray-300');
        adminBtn.classList.add('bg-gray-200');
        userType.value = 'cashier';
    });

    adminBtn.addEventListener('click', () => {
        adminBtn.classList.remove('bg-gray-200');
        adminBtn.classList.add('bg-gray-300');
        cashierBtn.classList.remove('bg-gray-300');
        cashierBtn.classList.add('bg-gray-200');
        userType.value = 'admin';
    });
</script>
</body>
</html>
