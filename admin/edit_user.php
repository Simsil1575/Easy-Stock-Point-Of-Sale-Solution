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

// Check if ID is provided
if (!isset($_GET['id'])) {
    header('Location: users.php');
    exit;
}

$db = new PDO('sqlite:../user.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    ensureUsersTableRoleIncludesWaitress($db);
} catch (Throwable $e) {
    error_log('edit_user ensure role constraint: ' . $e->getMessage());
}
require_once __DIR__ . '/../userdb_fingerprint_helpers.php';
userdb_ensure_fingerprint_columns($db);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];
    $allowedRoles = ['cashier', 'manager', 'admin', 'waitress', 'hubbly'];
    if (!in_array($role, $allowedRoles, true)) {
        header('Location: users.php?error=' . urlencode('Invalid role selected.'));
        exit;
    }
    $email = $_POST['email'] ?? null;
    $assignedCategory = isset($_POST['assigned_category']) ? trim((string) $_POST['assigned_category']) : '';
    if ($role === 'hubbly') {
        if ($assignedCategory === '') {
            header('Location: edit_user.php?id=' . urlencode((string) $id) . '&error=' . urlencode('Please select a product category for Hubbly users.'));
            exit;
        }
    } else {
        $assignedCategory = '';
    }

    $clearFingerprintLogin = isset($_POST['clear_fingerprint_login']) && $_POST['clear_fingerprint_login'] === '1';
    $efIdx = isset($_POST['enrolled_index_finger']) ? trim((string) $_POST['enrolled_index_finger']) : '';
    $efMid = isset($_POST['enrolled_middle_finger']) ? trim((string) $_POST['enrolled_middle_finger']) : '';
    if (!$clearFingerprintLogin) {
        if (($efIdx !== '' && $efMid === '') || ($efIdx === '' && $efMid !== '')) {
            header('Location: edit_user.php?id=' . urlencode((string) $id) . '&error=' . urlencode('Fingerprint enrollment incomplete. Clear fingerprints or finish enrollment.'));
            exit;
        }
    }

    // Prepare update query
    $query = "UPDATE users SET username = :username, role = :role, assigned_category = :assigned_category";
    $params = [
        ':username' => $username,
        ':role' => $role,
        ':assigned_category' => $assignedCategory !== '' ? $assignedCategory : null,
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

    if ($clearFingerprintLogin) {
        $query .= ', indexfinger = NULL, middlefinger = NULL';
    } elseif ($efIdx !== '' && $efMid !== '') {
        $query .= ', indexfinger = :indexfinger, middlefinger = :middlefinger';
        $params[':indexfinger'] = $efIdx;
        $params[':middlefinger'] = $efMid;
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

$productCategories = [];
try {
    $posDb = new PDO('sqlite:../pos.db');
    $posDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $productCategories = $posDb->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    // Keep current assigned category in the list even if products no longer use it
    $currentCat = trim((string) ($user['assigned_category'] ?? ''));
    if ($currentCat !== '' && !in_array($currentCat, $productCategories, true)) {
        array_unshift($productCategories, $currentCat);
    }
} catch (Throwable $e) {
    $productCategories = [];
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
<body class="bg-gray-100">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="content flex-1 lg:ml-64">
            <div class="w-full px-4 lg:px-6 py-6">
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
                                        <option value="manager" <?= $user['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        <option value="waitress" <?= $user['role'] === 'waitress' ? 'selected' : '' ?>>Waitress</option>
                                        <option value="hubbly" <?= $user['role'] === 'hubbly' ? 'selected' : '' ?>>Hubbly</option>
                                    </select>
                                </div>

                                <div id="hubblyCategoryWrap" class="<?= $user['role'] === 'hubbly' ? '' : 'hidden' ?>">
                                    <label for="assigned_category" class="block text-sm font-medium text-gray-700 mb-2">Hubbly Product Category</label>
                                    <select name="assigned_category" id="assigned_category"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out"
                                        <?= $user['role'] === 'hubbly' ? 'required' : '' ?>>
                                        <option value="">Select category…</option>
                                        <?php foreach ($productCategories as $cat): ?>
                                            <option value="<?= htmlspecialchars((string) $cat) ?>" <?= (($user['assigned_category'] ?? '') === $cat) ? 'selected' : '' ?>><?= htmlspecialchars((string) $cat) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">This user will only see products in the selected category on the Hubbly POS.</p>
                                </div>

                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email (Optional)</label>
                                    <input type="email" name="email" id="email"
                                        value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                                        placeholder="Enter email address"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>

                                <?php
                                $hasEnrolledFp =
                                    !empty(trim((string)($user['indexfinger'] ?? ''))) &&
                                    !empty(trim((string)($user['middlefinger'] ?? '')));
                                ?>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="clear_fingerprint_login" id="clear_fingerprint_login" value="1"
                                            class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                        <span class="text-sm font-medium text-gray-800">Remove fingerprint login for this user</span>
                                    </label>
                                    <p class="mt-2 text-xs text-gray-600">Check this before saving to clear stored templates. Leave unchecked to keep or replace via enrollment below.</p>
                                    <?php if ($hasEnrolledFp): ?>
                                    <p class="mt-2 text-xs text-teal-700"><i class="fas fa-fingerprint mr-1"></i>This user currently has fingerprint login enrolled.</p>
                                    <?php endif; ?>
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
    <script src="../finger/scripts/es6-shim.js"></script>
    <script src="../finger/scripts/websdk.client.bundle.min.js"></script>
    <script src="../finger/scripts/fingerprint.sdk.min.js"></script>
    <script src="../fingerprint_fp_raw.js?v=20260630"></script>
    <script>
        window.FP_ENROLL_API_URL = '../fingerprint_enroll_api.php';
    </script>
    <script src="../add_user_fingerprint.js?v=20260701"></script>
    <script>
        (function () {
            const roleEl = document.getElementById('role');
            const wrap = document.getElementById('hubblyCategoryWrap');
            const catEl = document.getElementById('assigned_category');
            function syncHubblyCategory() {
                const isHubbly = roleEl && roleEl.value === 'hubbly';
                if (wrap) wrap.classList.toggle('hidden', !isHubbly);
                if (catEl) catEl.required = !!isHubbly;
            }
            if (roleEl) {
                roleEl.addEventListener('change', syncHubblyCategory);
                syncHubblyCategory();
            }
        })();
    </script>
</body>
</html>
