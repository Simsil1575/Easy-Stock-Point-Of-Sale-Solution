<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate Software Key</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <link rel="icon" href="favicon.ico" type="image/png">

    <style>
        .sidebar {
            position: fixed;
            height: 100%;
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
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="sidebar">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 content">
            <div class="container mx-auto p-6">
                <h1 class="text-3xl font-bold mb-6">Activate Premium</h1>

                <div class="bg-white shadow-xl rounded-xl p-8 mb-8">
                    <form action="" method="POST" class="space-y-4">
                        <div class="flex-1 relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" />
                                </svg>
                            </div>
                            <input type="text" name="key" id="key" placeholder="Enter Your Premium Key" required
                                class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-600 focus:border-transparent shadow-sm placeholder-gray-400">
                        </div>
                        <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors duration-200">
                            <svg class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>
                            </svg>
                            Activate Premium
                        </button>
                    </form>

                    <?php
                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                        $submittedKey = $_POST['key'];
                        $pdo = new PDO('sqlite:key.db');

                        function decryptKey($encryptedKey) {
                            $encryptionKey = getenv('ENCRYPTION_KEY');
                            $data = base64_decode($encryptedKey);
                            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
                            $iv = substr($data, 0, $ivLength);
                            $ciphertext = substr($data, $ivLength);
                            return openssl_decrypt($ciphertext, 'aes-256-cbc', $encryptionKey, 0, $iv);
                        }

                        // Check if key exists and is not used
                        $stmt = $pdo->prepare("SELECT * FROM software_keys WHERE key = ? AND is_used = 0");
                        $stmt->execute([$submittedKey]);
                        $key = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($key) {
                            // Mark key as used
                            $updateStmt = $pdo->prepare("UPDATE software_keys SET is_used = 1 WHERE id = ?");
                            $updateStmt->execute([$key['id']]);
                            
                            echo "<div class='mt-4 p-4 bg-teal-100 text-teal-700 rounded fade-in'>
                                Premium activated successfully! You now have access to all premium features.
                            </div>";
                        } else {
                            echo "<div class='mt-4 p-4 bg-red-100 text-red-700 rounded fade-in'>
                                Invalid or already used key. Please try again.
                            </div>";
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
