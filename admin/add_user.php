<?php

require_once __DIR__ . '/../ensure_user_role_constraint.php';

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
    require_once __DIR__ . '/../userdb_fingerprint_helpers.php';

    $db = new PDO('sqlite:../user.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureUsersTableRoleIncludesWaitress($db);
    userdb_ensure_fingerprint_columns($db);

    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    $allowedRoles = ['cashier', 'manager', 'admin', 'waitress'];
    if ($role !== '' && !in_array($role, $allowedRoles, true)) {
        header('Location: add_user.php?error=' . urlencode('Invalid role selected.'));
        exit;
    }
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

    $efIdx = isset($_POST['enrolled_index_finger']) ? trim((string) $_POST['enrolled_index_finger']) : '';
    $efMid = isset($_POST['enrolled_middle_finger']) ? trim((string) $_POST['enrolled_middle_finger']) : '';
    if (($efIdx !== '' && $efMid === '') || ($efIdx === '' && $efMid !== '')) {
        header('Location: add_user.php?error=' . urlencode('Fingerprint enrollment incomplete. Clear fingerprints or finish enrollment.'));
        exit;
    }

    try {
        // Insert new user
        $stmt = $db->prepare(
            'INSERT INTO users (username, password_hash, role, email, indexfinger, middlefinger)
             VALUES (:username, :password_hash, :role, :email, :indexfinger, :middlefinger)'
        );
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        if ($efIdx !== '' && $efMid !== '') {
            $stmt->bindValue(':indexfinger', $efIdx, PDO::PARAM_STR);
            $stmt->bindValue(':middlefinger', $efMid, PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':indexfinger', null, PDO::PARAM_NULL);
            $stmt->bindValue(':middlefinger', null, PDO::PARAM_NULL);
        }
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
                    <a href="settings" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Settings
                    </a>
                    <a href="users" class="inline-flex items-center px-4 py-2 border border-gray-200 rounded-md shadow-sm text-sm font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400 transition duration-150 ease-in-out">
                        User list
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

                                <div id="fpAddUserEnrollmentWrap" class="mt-10 border-t border-gray-200 pt-8 md:mt-0 md:border-t-0 md:pt-0 md:border-l md:border-gray-200 md:pl-6">
                                    <h2 class="text-lg font-semibold text-gray-800 mb-2">
                                        <i class="fas fa-fingerprint mr-2 text-teal-600"></i>Fingerprint login (optional)
                                    </h2>
                                  

                                    <p id="fpAddUserStatus" class="text-sm font-medium min-h-[1.25rem] text-gray-700 mb-4"></p>
                                    <div class="flex flex-wrap items-end gap-3 mb-4">
                                        <div class="flex-1 min-w-[200px]">
                                            <label for="fpAddUserReaderSelect" class="block text-sm font-medium text-gray-700 mb-1">Reader</label>
                                            <select id="fpAddUserReaderSelect"
                                                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-teal-500 focus:border-teal-500 sm:text-sm"></select>
                                        </div>
                                        <button type="button" id="fpAddUserRefreshReadersBtn"
                                            class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                            Refresh readers
                                        </button>
                                    </div>
                                    <p class="text-sm font-medium text-gray-700 mb-2">Index finger (4 scans)</p>
                                    <div id="fpAddUserIndexFingers" class="grid grid-cols-4 gap-2 max-w-md mb-6">
                                        <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <div id="indexfingerFpAu<?php echo $i; ?>" class="flex justify-center p-2 rounded-lg border border-gray-100 bg-gray-50">
                                            <span data-state="empty" class="inline-block h-10 w-10 rounded-full border-2 border-gray-300 bg-gray-50"></span>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="text-sm font-medium text-gray-700 mb-2">Middle finger (4 scans)</p>
                                    <div id="fpAddUserMiddleFingers" class="grid grid-cols-4 gap-2 max-w-md mb-6">
                                        <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <div id="middleFingerFpAu<?php echo $i; ?>" class="flex justify-center p-2 rounded-lg border border-gray-100 bg-gray-50">
                                            <span data-state="empty" class="inline-block h-10 w-10 rounded-full border-2 border-gray-300 bg-gray-50"></span>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="flex flex-wrap gap-2 mb-4">
                                        <button type="button" id="fpAddUserStartCaptureBtn"
                                            class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-white bg-teal-600 hover:bg-teal-700">
                                            Start capture
                                        </button>
                                        <button type="button" id="fpAddUserFinishEnrollBtn"
                                            class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-white bg-teal-800 hover:bg-teal-900">
                                            Finish enrollment
                                        </button>
                                        <button type="button" id="fpAddUserClearBtn"
                                            class="inline-flex items-center px-4 py-2 rounded-md border border-gray-300 text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                            Clear fingerprints
                                        </button>
                                    </div>
                                    <input type="hidden" name="enrolled_index_finger" id="enrolled_index_finger" value="">
                                    <input type="hidden" name="enrolled_middle_finger" id="enrolled_middle_finger" value="">
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
    <script src="../finger/scripts/es6-shim.js"></script>
    <script src="../finger/scripts/websdk.client.bundle.min.js"></script>
    <script src="../finger/scripts/fingerprint.sdk.min.js"></script>
    <script src="../fingerprint_fp_raw.js?v=20260630"></script>
    <script>
        window.FP_ENROLL_API_URL = '../fingerprint_enroll_api.php';
    </script>
    <script src="../add_user_fingerprint.js?v=20260701"></script>
</body>
</html>
