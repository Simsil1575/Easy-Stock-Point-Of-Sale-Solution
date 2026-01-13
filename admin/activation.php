<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate POS System</title>
      <link href="src/output.css" rel="stylesheet">    <link rel="icon" href="favicon.ico" type="image/png">
</head>
<body class="min-h-screen bg-gradient-to-br from-teal-100 via-white to-purple-100 flex items-center justify-center p-6">
    <div class="bg-white/80 backdrop-blur-lg rounded-2xl shadow-xl max-w-md w-full p-8 border border-gray-100">
        <div class="text-center mb-8">
            <div class="flex justify-center mb-4">
                <div class="bg-teal-600 p-3 rounded-xl">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
            </div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Activate Your POS System</h1>
            <p class="mt-3 text-gray-500">Enter your activation key to get started</p>
        </div>

        <form method="POST" action="verify_activation.php" class="space-y-6">
            <div class="space-y-2">
                <label for="activation_key" class="block text-sm font-medium text-gray-700">Activation Key</label>
                <div class="relative">
                    <input type="text" id="activation_key" name="activation_key" required 
                        class="block w-full px-4 py-3 border border-gray-200 rounded-xl shadow-sm focus:ring-2 focus:ring-teal-500 focus:border-transparent transition duration-200"
                        placeholder="XXXX-XXXX-XXXX-XXXX">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                        </svg>
                    </div>
                </div>
            </div>

            <button type="submit" 
                class="w-full flex justify-center items-center px-6 py-3 border border-transparent rounded-xl text-base font-medium text-white bg-gradient-to-r from-teal-600 to-purple-600 hover:from-teal-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transform transition duration-200 hover:scale-[1.02] shadow-lg hover:shadow-xl">
                Activate System
                <svg class="ml-2 -mr-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
            </button>
        </form>

        <?php if (isset($_GET['error'])): ?>
        <div class="mt-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg" role="alert">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-700">Invalid activation key. Please try again.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-8 text-center">
            <p class="text-sm text-gray-500">
                Need an activation key? 
                <a href="#" class="font-medium text-teal-600 hover:text-teal-500 transition duration-150 underline decoration-2 decoration-teal-500/30 hover:decoration-teal-500">
                    Contact support
                </a>
            </p>
        </div>
    </div>
</body>
</html>
