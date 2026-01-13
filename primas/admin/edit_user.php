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

// Check if ID is provided
if (!isset($_GET['id'])) {
    header('Location: users.php');
    exit;
}

$db = new PDO('sqlite:../user.db');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];
    $email = $_POST['email'] ?? null;

    // Prepare update query
    $query = "UPDATE users SET username = :username, role = :role";
    $params = [
        ':username' => $username,
        ':role' => $role,
        ':id' => $id
    ];

    // Update password if provided
    if (!empty($password)) {
        $password_hash = md5($password);
        $query .= ", password_hash = :password_hash";
        $params[':password_hash'] = $password_hash;
    }

    // Update email if provided
    if ($email !== null) {
        $query .= ", email = :email";
        $params[':email'] = $email;
    }

    $query .= " WHERE id = :id";

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    header('Location: users.php?update=success');
    exit;
}

// Fetch user data
$id = $_GET['id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: users.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
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
                    <h1 class="text-2xl font-semibold text-gray-800 mb-8">Edit User</h1>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="id" value="<?= $user['id'] ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Left Column - Input Fields -->
                            <div class="space-y-6">
                                <div>
                                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                    <input type="text" name="username" id="username" required
                                        value="<?= htmlspecialchars($user['username']) ?>"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>

                                <div>
                                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                                    <input type="password" name="password" id="password"
                                        placeholder="Leave blank to keep current password"
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
                                        <option value="cashier" <?= $user['role'] === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Manager</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email (Optional)</label>
                                    <input type="email" name="email" id="email"
                                        value="<?= htmlspecialchars($user['email']) ?>"
                                        placeholder="Enter email address"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-4 mt-6">
                            <a href="users.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition duration-150 ease-in-out">
                                Cancel
                            </a>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                                Update User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
