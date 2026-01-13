<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    try {
        if ($new_password !== $confirm_password) {
            throw new Exception("Passwords do not match!");
        }

        if (strlen($new_password) < 8) {
            throw new Exception("Password must be at least 8 characters long!");
        }

        $db = new PDO('sqlite:user.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if username exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        if (!$stmt->fetch()) {
            throw new Exception("Username not found!");
        }

        // Update password
        $password_hash = md5($new_password);
        $update_stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
        $update_stmt->execute([$password_hash, $username]);

        $success_message = "Password reset successfully! Redirecting to login...";
        header("Location: ?success=" . urlencode($success_message));
        exit();

    } catch(Exception $e) {
        $error_message = $e->getMessage();
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?error=" . urlencode($error_message));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Easy Stock POS</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="h-full bg-gray-100 font-sans">
    <div class="flex min-h-screen">
        <!-- Hero Image Section -->
        <div class="hidden lg:block lg:w-1/2">
            <img src="props/l__FXH4148.jpg" alt="Hero Image" class="object-cover w-full h-full">
        </div>

        <!-- Reset Password Form Section -->
        <div class="flex flex-col justify-center px-6 py-12 lg:w-1/2 lg:px-8">
            <div class="sm:mx-auto sm:w-full sm:max-w-sm">
                <img src="logo.png" alt="Company Logo" class="mx-auto h-16 w-auto">
                <h2 class="mt-6 text-center text-2xl font-bold tracking-tight text-black">
                    <i class="fas fa-key mr-2"></i>Reset Password
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    Enter your username and new password below
                </p>
            </div>

            <div class="mt-10 sm:mx-auto sm:w-full sm:max-w-sm bg-gray-250 rounded-xl p-6">
                <?php if (isset($_GET['error'])): ?>
                    <div id="errorAlert" class="mb-4 bg-red-600 text-white px-4 py-3 rounded-lg flex items-center" role="alert">
                        <i class="fas fa-exclamation-triangle mr-3"></i>
                        <span class="flex-1"><?php echo htmlspecialchars($_GET['error']); ?></span>
                        <i class="fas fa-times cursor-pointer" onclick="document.getElementById('errorAlert').style.display='none'"></i>
                    </div>
                <?php endif; ?>

                <form class="space-y-6" method="POST">
                    <div>
                        <label for="username" class="block text-sm font-medium text-black">Username</label>
                        <div class="mt-2 relative">
                            <input type="text" id="username" name="username" required
                                   class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200">
                            <i class="fas fa-user absolute left-3 top-2.5 text-gray-400"></i>
                        </div>
                    </div>

                    <div>
                        <label for="new_password" class="block text-sm font-medium text-black">New Password</label>
                        <div class="mt-2 relative">
                            <input type="password" id="new_password" name="new_password" required
                                   class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200">
                            <i class="fas fa-lock absolute left-3 top-2.5 text-gray-400"></i>
                        </div>
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-black">Confirm Password</label>
                        <div class="mt-2 relative">
                            <input type="password" id="confirm_password" name="confirm_password" required
                                   class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200">
                            <i class="fas fa-lock absolute left-3 top-2.5 text-gray-400"></i>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <button type="submit" 
                                class="flex w-full justify-center rounded-lg bg-gray-300 px-4 py-2.5 text-sm font-semibold text-black shadow-md hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-300 transition duration-200">
                            <i class="fas fa-save mr-2"></i>Reset Password
                        </button>
                    </div>
                </form>

                <div class="mt-6">
                    <a href="index" class="flex items-center justify-center text-sm text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Login
                    </a>
                </div>
            </div>

            <div class="mt-8 text-center text-sm text-gray-500">
                <p>&copy; <?php echo date('Y'); ?> Simsil Tech Solutions CC. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
