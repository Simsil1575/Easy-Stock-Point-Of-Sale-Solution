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
?>

<?php
// Database connection
$db = new PDO('sqlite:../user.db');


// Fetch all managers and cashiers
$users = $db->query("SELECT * FROM users WHERE role IN ('cashier', 'manager') ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio,line-clamp"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
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
    <!-- Toast Notification Container -->
    <div id="toast" class="fixed top-4 right-4 z-50 hidden max-w-xs w-full bg-white rounded-lg shadow-lg border-l-4 p-4 transition-transform duration-300 transform translate-x-full">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <div class="toast-icon"></div>
                <p class="toast-message text-sm"></p>
            </div>
            <button onclick="hideToast()" class="ml-4 text-gray-400 hover:text-gray-500">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 content">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex justify-between items-center mb-8">
                    <h1 class="text-3xl font-bold">Account Management</h1>

                    <a href="settings" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
      </svg>
      Go Back
  </a>
                    <a href="add_user" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add User
                    </a>
                </div>

                <!-- Users Table -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $user['id'] ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($user['username']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= ucfirst($user['role']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="edit_user.php?id=<?= $user['id'] ?>" class="text-teal-600 hover:text-teal-900 mr-3">Edit</a>
                                        <a href="delete_user.php?id=<?= $user['id'] ?>" class="text-red-600 hover:text-red-900">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Toast handling
    function showToast(type, message) {
        const toast = document.getElementById('toast');
        const icon = toast.querySelector('.toast-icon');
        const msgElement = toast.querySelector('.toast-message');
        
        // Set styling based on type
        toast.classList.remove('border-red-500', 'border-teal-500', 'bg-red-50', 'bg-teal-50');
        const [borderClass, bgClass] = type === 'success' 
            ? ['border-teal-500', 'bg-teal-50'] 
            : ['border-red-500', 'bg-red-50'];
        toast.classList.add(borderClass, bgClass);
        
        msgElement.textContent = message;
        toast.classList.remove('translate-x-full');
        toast.classList.remove('hidden');
        
        // Auto-hide after 4 seconds
        setTimeout(hideToast, 4000);
    }

    function hideToast() {
        const toast = document.getElementById('toast');
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.classList.add('hidden'), 300);
    }

    // Check for URL parameters on page load
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        
        if (urlParams.has('delete')) {
            const status = urlParams.get('delete');
            const message = status === 'success' 
                ? 'User deleted successfully' 
                : `Error: ${decodeURIComponent(urlParams.get('message') || 'Failed to delete user')}`;
            showToast(status, message);
        }
        
        if (urlParams.has('add')) {
            showToast('success', 'User added successfully');
        }
    });
    </script>
</body>
</html>
