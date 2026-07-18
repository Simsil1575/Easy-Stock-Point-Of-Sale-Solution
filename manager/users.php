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


// Fetch all managers, cashiers, and waitresses
$users = $db->query("SELECT * FROM users WHERE role IN ('cashier', 'manager', 'waitress') ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
    <script src="../lucide.js"></script>

</head>
<body class="bg-gray-100">
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
        <div class="content flex-1 lg:ml-64">
            <div class="w-full px-4 lg:px-6 py-6">
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
                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:divide-gray-700 dark:divide-gray-700 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                                        <div class="relative max-w-xs w-full md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="hs-table-with-pagination-search" class="py-2 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-slate-900 dark:border-gray-700 dark:text-gray-400 dark:focus:ring-gray-600" placeholder="Search for users">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <!-- Role Filter -->
                                        <div class="flex gap-2 items-center">
                                            <select id="roleFilter" class="py-2 px-3 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="">All Roles</option>
                                                <option value="cashier">Cashier</option>
                                                <option value="manager">Manager</option>
                                                <option value="waitress">Waitress</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Table -->
                                <div class="overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(0)">
                                                    ID <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(1)">
                                                    Username <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(2)">
                                                    Role <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="usersTableBody" class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <?php if (empty($users)): ?>
                                                <tr>
                                                    <td colspan="4" class="px-6 py-12 text-center">
                                                        <i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                        <p class="text-gray-500 text-lg">No users found. Add your first user.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($users as $user): ?>
                                                    <tr class="user-row hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" 
                                                        data-user-id="<?= $user['id'] ?>"
                                                        data-username="<?= htmlspecialchars(strtolower($user['username'])) ?>"
                                                        data-role="<?= strtolower($user['role']) ?>">
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200"><?= $user['id'] ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($user['username']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200">
                                                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?= 
                                                                $user['role'] === 'manager' ? 'bg-purple-100 text-purple-800' : 
                                                                ($user['role'] === 'waitress' ? 'bg-pink-100 text-pink-800' : 
                                                                'bg-blue-100 text-blue-800') ?>">
                                                                <?= ucfirst($user['role']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                                                            <div class="flex items-center justify-end gap-2">
                                                                <a href="edit_user.php?id=<?= $user['id'] ?>" 
                                                                   class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800 disabled:opacity-50 disabled:pointer-events-none dark:text-blue-500 dark:hover:text-blue-400 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600"
                                                                   title="Edit">
                                                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                                                </a>
                                                                <a href="delete_user.php?id=<?= $user['id'] ?>" 
                                                                   class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800 disabled:opacity-50 disabled:pointer-events-none dark:text-red-500 dark:hover:text-red-400"
                                                                   title="Delete">
                                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <div class="py-1 px-4">
                                    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                                        <div class="text-sm text-gray-700 dark:text-gray-300">
                                            Showing <span id="showingFrom">1</span> to <span id="showingTo"><?= min(10, count($users)) ?></span> of <span id="totalRows"><?= count($users) ?></span> entries
                                        </div>
                                        <nav class="flex items-center space-x-1" id="paginationNav">
                                            <!-- Pagination buttons will be generated by JavaScript -->
                                        </nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Table management variables
        let currentPage = 1;
        let rowsPerPage = 10;
        let currentSortColumn = -1;
        let sortDirection = 'asc';
        let allRows = [];
        let filteredRows = [];

        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // Get all table rows
            const tableBody = document.getElementById('usersTableBody');
            allRows = Array.from(tableBody.querySelectorAll('.user-row'));
            filteredRows = [...allRows];

            // Initialize table
            initializeTable();

            // Search functionality
            const searchInput = document.getElementById('hs-table-with-pagination-search');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    filterTable();
                });
            }

            // Role filter
            const roleFilter = document.getElementById('roleFilter');
            if (roleFilter) {
                roleFilter.addEventListener('change', function() {
                    filterTable();
                });
            }

            // Auto-hide success/error messages after 5 seconds
            const alerts = document.querySelectorAll('[role="alert"]');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        });

        // Initialize table with pagination
        function initializeTable() {
            filterTable();
        }

        // Filter table based on search and filters
        function filterTable() {
            const searchInput = document.getElementById('hs-table-with-pagination-search');
            const roleFilter = document.getElementById('roleFilter');
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const roleValue = roleFilter ? roleFilter.value : '';

            filteredRows = allRows.filter(row => {
                const username = row.getAttribute('data-username') || '';
                const role = row.getAttribute('data-role') || '';
                const userId = row.getAttribute('data-user-id') || '';

                // Search filter
                const matchesSearch = searchTerm === '' || 
                    username.includes(searchTerm) || 
                    role.includes(searchTerm) ||
                    userId.includes(searchTerm);

                // Role filter
                const matchesRole = roleValue === '' || role === roleValue;

                return matchesSearch && matchesRole;
            });

            currentPage = 1;
            renderTable();
        }

        // Sort table
        function sortTable(columnIndex) {
            if (currentSortColumn === columnIndex) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortColumn = columnIndex;
                sortDirection = 'asc';
            }

            filteredRows.sort((a, b) => {
                let aValue, bValue;

                switch(columnIndex) {
                    case 0: // ID
                        aValue = parseInt(a.getAttribute('data-user-id') || 0);
                        bValue = parseInt(b.getAttribute('data-user-id') || 0);
                        break;
                    case 1: // Username
                        aValue = a.getAttribute('data-username') || '';
                        bValue = b.getAttribute('data-username') || '';
                        break;
                    case 2: // Role
                        aValue = a.getAttribute('data-role') || '';
                        bValue = b.getAttribute('data-role') || '';
                        break;
                    default:
                        return 0;
                }

                if (typeof aValue === 'number') {
                    return sortDirection === 'asc' ? aValue - bValue : bValue - aValue;
                } else {
                    return sortDirection === 'asc' 
                        ? aValue.localeCompare(bValue)
                        : bValue.localeCompare(aValue);
                }
            });

            renderTable();
        }

        // Render table with pagination
        function renderTable() {
            const tableBody = document.getElementById('usersTableBody');
            const totalRows = filteredRows.length;
            const totalPages = Math.ceil(totalRows / rowsPerPage);
            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = startIndex + rowsPerPage;
            const pageRows = filteredRows.slice(startIndex, endIndex);

            // Clear table body
            tableBody.innerHTML = '';

            // Add rows for current page
            if (pageRows.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="4" class="px-6 py-12 text-center"><i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i><p class="text-gray-500 text-lg">No users found matching your criteria.</p></td></tr>';
            } else {
                pageRows.forEach(row => {
                    tableBody.appendChild(row);
                });
            }

            // Update pagination info
            document.getElementById('showingFrom').textContent = totalRows === 0 ? 0 : startIndex + 1;
            document.getElementById('showingTo').textContent = Math.min(endIndex, totalRows);
            document.getElementById('totalRows').textContent = totalRows;

            // Render pagination
            renderPagination(totalPages);

            // Reinitialize icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        // Render pagination buttons
        function renderPagination(totalPages) {
            const paginationNav = document.getElementById('paginationNav');
            paginationNav.innerHTML = '';

            if (totalPages <= 1) return;

            // Previous button
            const prevButton = document.createElement('button');
            prevButton.type = 'button';
            prevButton.className = 'p-2.5 inline-flex items-center gap-x-2 text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none dark:text-white dark:hover:bg-white/10 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600';
            prevButton.disabled = currentPage === 1;
            prevButton.innerHTML = '<span aria-hidden="true">«</span><span class="sr-only">Previous</span>';
            prevButton.onclick = () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            };
            paginationNav.appendChild(prevButton);

            // Page number buttons
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    const pageButton = document.createElement('button');
                    pageButton.type = 'button';
                    pageButton.className = `min-w-[40px] flex justify-center items-center text-gray-800 hover:bg-gray-100 py-2.5 text-sm rounded-full disabled:opacity-50 disabled:pointer-events-none dark:text-white dark:hover:bg-white/10 ${i === currentPage ? 'bg-gray-100 dark:bg-white/10' : ''}`;
                    pageButton.textContent = i;
                    pageButton.onclick = () => {
                        currentPage = i;
                        renderTable();
                    };
                    paginationNav.appendChild(pageButton);
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'px-2 text-gray-500';
                    ellipsis.textContent = '...';
                    paginationNav.appendChild(ellipsis);
                }
            }

            // Next button
            const nextButton = document.createElement('button');
            nextButton.type = 'button';
            nextButton.className = 'p-2.5 inline-flex items-center gap-x-2 text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none dark:text-white dark:hover:bg-white/10 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600';
            nextButton.disabled = currentPage === totalPages;
            nextButton.innerHTML = '<span class="sr-only">Next</span><span aria-hidden="true">»</span>';
            nextButton.onclick = () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            };
            paginationNav.appendChild(nextButton);
        }

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
