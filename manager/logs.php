

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
// Check activation status
$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs</title>
    <script src="../navigation.js" async></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <link href="../src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.4.16.min.js"></script>

    <style>
        .sidebar {
            position: fixed;
            height: 100%;
        }
        .content {
            margin-left: 250px; /* Adjust this value based on the width of your sidebar */
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 14px;
        }
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            color: #374151;
            font-weight: 500;
            vertical-align: middle;
        }
        th {
            background-color: #f9fafb; 
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        tr {
            transition: all 0.2s ease;
        }
        tr:hover {
            background-color: #f3f4f6;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .table-container {
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        .sort-icon {
            opacity: 0.5;
            transition: all 0.2s;
        }
        th:hover .sort-icon {
            opacity: 1;
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="sidebar">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="content flex-1 lg:ml-64">
            <div class="w-full p-4 lg:p-6">
                <div class="flex items-center justify-between mb-6">
                    <a href="settings" class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Dashboard
                    </a>
                    <h1 class="text-3xl font-bold">Activity Logs</h1>
                </div>

                <?php
                try {
                    require_once __DIR__ . '/../audit_log_helper.php';
                    $pos_db = new PDO('sqlite:../pos.db');
                    $pos_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $user_db = null;
                    try {
                        $user_db = new PDO('sqlite:../user.db');
                        $user_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    } catch (PDOException $e) {
                    }
                    $logs = buildAuditLogActivityRows($pos_db, $user_db, 5000);
                } catch (PDOException $e) {
                    $logs = [];
                    $error = "Error fetching logs: " . $e->getMessage();
                }
                ?>

                <?php if (!empty($error)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <div class="mb-6 flex items-end justify-between gap-6">
                    <div class="flex-1">
                        <div class="relative flex-grow max-w-72">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                                </svg>
                            </div>
                            <input type="text" id="search" onkeyup="filterLogs()" placeholder="Search by any field..." 
                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none focus:border-blue-500 shadow-sm transition duration-200">
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                    <div class="table-container">
                        <table class="min-w-full table-auto">
                            <thead>
                                <tr class="bg-gray-100 border-b-2 border-gray-200">
                                    <th class="py-4 px-6 text-left cursor-pointer" onclick="sortTable(0)">
                                        <div class="flex items-center">
                                            <span class="text-gray-700">Type</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="py-4 px-6 text-left cursor-pointer" onclick="sortTable(1)">
                                        <div class="flex items-center">
                                            <span class="text-gray-700">User</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="py-4 px-6 text-left cursor-pointer" onclick="sortTable(2)">
                                        <div class="flex items-center">
                                            <span class="text-gray-700">Action</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="py-4 px-6 text-left cursor-pointer" onclick="sortTable(3)">
                                        <div class="flex items-center">
                                            <span class="text-gray-700">Details</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="py-4 px-6 text-left cursor-pointer" onclick="sortTable(4, true)">
                                        <div class="flex items-center">
                                            <span class="text-gray-700">Amount</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="py-4 px-6 text-left cursor-pointer" onclick="sortTable(5)">
                                        <div class="flex items-center">
                                            <span class="text-gray-700">Time</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200" id="logsTableBody">
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="6" class="py-6 px-6 text-center text-gray-500 italic">No activity logs found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <?php
                                        $source = (string) ($log['source'] ?? '');
                                        if ($source === 'auth') {
                                            $sourceClass = 'bg-purple-100 text-purple-800';
                                        } elseif ($source === 'sale') {
                                            $sourceClass = 'bg-teal-100 text-teal-800';
                                        } elseif ($source === 'receiving') {
                                            $sourceClass = 'bg-indigo-100 text-indigo-800';
                                        } elseif ($source === 'adjustment') {
                                            $sourceClass = 'bg-orange-100 text-orange-800';
                                        } elseif ($source === 'opening_stock') {
                                            $sourceClass = 'bg-green-100 text-green-800';
                                        } elseif ($source === 'closing_stock') {
                                            $sourceClass = 'bg-yellow-100 text-yellow-800';
                                        } else {
                                            $sourceClass = 'bg-blue-100 text-blue-800';
                                        }
                                        $action = (string) ($log['action_type'] ?? '');
                                        if ($action === 'login' || $action === 'sale') {
                                            $actionClass = 'bg-teal-100 text-teal-800';
                                        } elseif ($action === 'logout') {
                                            $actionClass = 'bg-red-100 text-red-800';
                                        } elseif ($action === 'receiving') {
                                            $actionClass = 'bg-indigo-100 text-indigo-800';
                                        } elseif ($action === 'adjust' || $action === 'damaged') {
                                            $actionClass = 'bg-orange-100 text-orange-800';
                                        } else {
                                            $actionClass = 'bg-blue-100 text-blue-800';
                                        }
                                        ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="py-4 px-6">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $sourceClass ?>">
                                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $source))) ?>
                                                </span>
                                            </td>
                                            <td class="py-4 px-6 text-sm text-gray-500">
                                                <?= htmlspecialchars($log['username'] ?: ($log['user_id'] ?: '-')) ?>
                                            </td>
                                            <td class="py-4 px-6">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $actionClass ?>">
                                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $action))) ?>
                                                </span>
                                            </td>
                                            <td class="py-4 px-6 text-sm text-gray-500">
                                                <?= htmlspecialchars($log['detail'] ?? '-') ?>
                                            </td>
                                            <td class="py-4 px-6 text-sm text-gray-500">
                                                <?= isset($log['amount']) && $log['amount'] !== null && $log['amount'] !== '' ? 'N$' . number_format((float) $log['amount'], 2) : '-' ?>
                                            </td>
                                            <td class="py-4 px-6 text-sm text-gray-500">
                                                <?= !empty($log['action_time']) ? date('M j, Y g:i A', strtotime($log['action_time'])) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <!-- Pagination Controls -->
                        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                            <div class="flex justify-between items-center">
                                <div class="flex gap-2">
                                    <button id="firstPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                                        </svg>
                                    </button>
                                    <button id="prevPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                        </svg>
                                        Prev
                                    </button>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span id="pageNumber" class="text-sm text-gray-700 font-medium">Page 1 of 1</span>
                                    <div class="flex items-center gap-2">
                                        <input type="number" id="pageInput" min="1" class="w-20 px-3 py-2 border border-gray-300 rounded-md text-sm shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Page">
                                        <button class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 transition-colors shadow-sm">Go</button>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button id="nextPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        Next
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                    <button id="lastPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function filterLogs() {
        const input = document.getElementById('search');
        const filter = input.value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr');

        rows.forEach(row => {
            const cells = row.getElementsByTagName('td');
            let showRow = false;
            
            Array.from(cells).forEach(cell => {
                if (cell.textContent.toLowerCase().includes(filter)) {
                    showRow = true;
                }
            });
            
            row.style.display = showRow ? '' : 'none';
        });
    }

    // Pagination and Sorting Script
    const rowsPerPage = 5;
    const tableBody = document.getElementById("logsTableBody");
    let allRows = Array.from(tableBody.children);
    let rows = [...allRows];
    const pageNumber = document.getElementById("pageNumber");
    let sortDirection = {};
    let currentPage = 1;

    function showPage(page) {
        const start = (page - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
        
        allRows.forEach(row => row.style.display = 'none');
        rows.slice(start, end).forEach(row => row.style.display = 'table-row');
        
        pageNumber.textContent = `Page ${page} of ${maxPage}`;
        document.getElementById('pageInput').value = page;
        document.getElementById('pageInput').placeholder = `Page (1-${maxPage})`;
    }

    function sortTable(columnIndex, isNumeric = false) {
        if (!sortDirection[columnIndex]) {
            sortDirection[columnIndex] = 'asc';
        } else {
            sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
        }

        rows.sort((a, b) => {
            let aValue = a.children[columnIndex].textContent.trim();
            let bValue = b.children[columnIndex].textContent.trim();

            if (isNumeric) {
                aValue = parseFloat(aValue.replace('N$', '').replace(',', ''));
                bValue = parseFloat(bValue.replace('N$', '').replace(',', ''));
            } else {
                aValue = aValue.toLowerCase();
                bValue = bValue.toLowerCase();
            }

            if (sortDirection[columnIndex] === 'asc') {
                return aValue > bValue ? 1 : -1;
            } else {
                return aValue < bValue ? 1 : -1;
            }
        });

        while (tableBody.firstChild) {
            tableBody.removeChild(tableBody.firstChild);
        }
        rows.forEach(row => tableBody.appendChild(row));

        showPage(currentPage);
    }

    // Pagination Event Listeners
    document.getElementById("prevPage").addEventListener("click", () => {
        if (currentPage > 1) {
            currentPage--;
            showPage(currentPage);
        }
    });

    document.getElementById("nextPage").addEventListener("click", () => {
        if (currentPage * rowsPerPage < rows.length) {
            currentPage++;
            showPage(currentPage);
        }
    });

    document.getElementById("firstPage").addEventListener("click", () => {
        currentPage = 1;
        showPage(currentPage);
    });

    document.getElementById("lastPage").addEventListener("click", () => {
        currentPage = Math.ceil(rows.length / rowsPerPage);
        showPage(currentPage);
    });

    document.getElementById("pageInput").addEventListener("change", () => {
        const desiredPage = parseInt(document.getElementById('pageInput').value);
        if (!isNaN(desiredPage)) {
            const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
            currentPage = Math.min(Math.max(1, desiredPage), maxPage);
            showPage(currentPage);
        }
    });

    // Initialize the page display
    showPage(currentPage);
    </script>
</body>
</html>
