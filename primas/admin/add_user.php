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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new PDO('sqlite:../user.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    $email = isset($_POST['email']) && $_POST['email'] !== '' ? trim($_POST['email']) : null;

    if ($username === '' || $password === '' || $role === '') {
        header('Location: add_user.php?error=' . urlencode('Please fill in all required fields.'));
        exit;
    }

    // Check if username already exists (graceful handling of UNIQUE constraint)
    $existsStmt = $db->prepare("SELECT 1 FROM users WHERE username = :username LIMIT 1");
    $existsStmt->bindValue(':username', $username, PDO::PARAM_STR);
    $existsStmt->execute();
    if ($existsStmt->fetchColumn()) {
        header('Location: add_user.php?error=' . urlencode('Username already exists. Choose a different one.'));
        exit;
    }

    // Hash the password (kept md5 to remain compatible with existing logins)
    $password_hash = md5($password);

    try {
        // Insert new user
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, role, email) VALUES (:username, :password_hash, :role, :email)");
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        header('Location: users?add=success');
        exit;
    } catch (PDOException $e) {
        // Fallback for any DB error (including race condition on UNIQUE)
        $msg = strpos($e->getMessage(), 'UNIQUE') !== false ? 'Username already exists. Choose a different one.' : 'Failed to add user: ' . $e->getMessage();
        header('Location: add_user.php?error=' . urlencode($msg));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User</title>
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
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 content">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex gap-3">
                    <a href="users" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Go Back
                    </a>
                </div><br>
                <div class="bg-white rounded-lg shadow-lg p-8">
                    <?php if (isset($_GET['error']) && $_GET['error'] !== ''): ?>
                    <div class="mb-6 p-4 rounded-md bg-red-50 border border-red-200 text-red-700">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($_GET['error']); ?>
                    </div>
                    <?php endif; ?>
                    <h1 class="text-2xl font-semibold text-gray-800 mb-8">Add New User</h1>
                    
                    <form method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Left Column - Input Fields -->
                            <div class="space-y-6">
                                <div>
                                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                    <input type="text" name="username" id="username" required
                                        placeholder="Enter username"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>

                                <div>
                                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                                    <input type="password" name="password" id="password" required
                                        placeholder="Enter password"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>

                                <div>
                                    <label for="role" class="block text-sm font-medium text-gray-700 mb-2">Role</label>
                                    <select name="role" id="role" required
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                        <option value="cashier">Cashier</option>
                                        <option value="manager">Manager</option>
                                        <option value="admin">Admin</option>
                                        <option value="waitress">Waitress</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email (Optional)</label>
                                    <input type="email" name="email" id="email"
                                        placeholder="Enter email address"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-4 mt-6">
                            <a href="users" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition duration-150 ease-in-out">
                                Cancel
                            </a>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                                Add User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
