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


$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

$db = new PDO('sqlite:../pos.db');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $reason = $_POST['reason'];
    $damageDate = isset($_POST['date']) ? preg_replace('/[^0-9\-]/', '', $_POST['date']) : '';
    if ($damageDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $damageDate)) {
        $damageDate = date('Y-m-d');
    }
    $damageDateTime = $damageDate . ' 10:00:00';

    try {
        $db->beginTransaction();
        
        // Get current product quantity before update
        $stmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $old_quantity = $stmt->fetchColumn();

        // Check if requested quantity is available
        if ($quantity > $old_quantity) {
            throw new Exception("Cannot damage more items than are available in stock");
        }
        
        // Insert into damaged goods with selected date at 10:00
        $stmt = $db->prepare("INSERT INTO damaged_goods (product_id, quantity, reason, date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$product_id, $quantity, $reason, $damageDateTime]);
        
        // Update product quantity
        $stmt = $db->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        $stmt->execute([$quantity, $product_id]);
        
        // Get new product quantity after update
        $stmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $new_quantity = $stmt->fetchColumn();
        
        // Insert into stock_changes
        $stmt = $db->prepare("INSERT INTO stock_changes (product_id, action, quantity_change, old_quantity, new_quantity) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$product_id, 'damaged', -1, $old_quantity, $new_quantity]);
        
        $db->commit();
        
        // Store success message in session and redirect
        $_SESSION['success'] = "Damaged goods recorded successfully!";
        header('Location: damaged_goods');
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Error recording damaged goods: " . $e->getMessage();
        header('Location: damaged_goods');
        exit();
    }
}

// Get success/error messages from session if they exist
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Get products and damaged goods records
$products = $db->query("SELECT id, name FROM products")->fetchAll(PDO::FETCH_ASSOC);
$damagedGoods = $db->query("
    SELECT d.*, p.name as product_name 
    FROM damaged_goods d
    JOIN products p ON d.product_id = p.id
    ORDER BY d.date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damaged Goods Tracking</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../lucide.js"></script>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 content">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex justify-between items-center mb-8 border-b border-gray-200 pb-6">
                    <div class="flex items-center gap-3">
                        <a href="manager-center" class="inline-flex items-center px-3 py-2 sm:px-4 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition-colors text-sm flex-shrink-0">
                            <svg class="w-5 h-5 mr-1.5 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            <span class="hidden sm:inline">back</span>
                        </a>
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Damaged Goods Tracking</h1>
                            <p class="mt-2 text-sm text-gray-500">Manage and track damaged inventory items</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-50 px-4 py-2 rounded-lg">
                            <span class="text-blue-700 font-medium">Total Damaged Items:</span>
                            <?php 
                                $total = $db->query("SELECT SUM(quantity) FROM damaged_goods")->fetchColumn();
                                echo '<span class="ml-2 text-blue-800">'.($total ?: 0).'</span>';
                            ?>
                        </div>
                        <a href="settings" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Go Back
                        </a>
                    </div>
                </div>

                <?php if(isset($success)): ?>
                    <div class="mb-8 p-4 bg-teal-50 border-l-4 border-teal-400">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-teal-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <p class="ml-3 text-sm text-teal-700"><?= $success ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if(isset($error)): ?>
                    <div class="mb-8 p-4 bg-red-50 border-l-4 border-red-400">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                            <p class="ml-3 text-sm text-red-700"><?= $error ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-white rounded-xl shadow-sm p-6 mb-8 border border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900 mb-6">Record Damaged Goods</h2>
                    <form method="POST">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Date <span class="text-red-500">*</span></label>
                                <input type="date" name="date" value="<?= date('Y-m-d') ?>"
                                    class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8" required>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Product <span class="text-red-500">*</span></label>
                                <select name="product_id" class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8">
                                    <?php foreach($products as $product): ?>
                                        <option value="<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Quantity <span class="text-red-500">*</span></label>
                                <input type="number" name="quantity" min="1" class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8" required
                                    oninput="checkQuantity(this)">
                                <p id="quantity-error" class="text-red-500 text-sm hidden">Cannot damage more items than are available in stock</p>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Reason</label>
                                <input type="text" name="reason" class="mt-1 block w-full rounded-lg border border-gray-400 shadow-sm focus:border-teal-500 focus:ring-teal-500 h-8">
                            </div>
                        </div>
                        <button type="submit" class="mt-6 px-6 py-3 bg-red-500 text-white font-medium rounded-lg hover:bg-red-700 transition-colors duration-200 flex items-center justify-center gap-2 h-12">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            Record Damage
                        </button>
                    </form>
                </div>

                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:divide-gray-700 dark:divide-gray-700 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                                        <div class="relative max-w-xs w-full md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="hs-table-with-pagination-search" class="py-2 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-slate-900 dark:border-gray-700 dark:text-gray-400 dark:focus:ring-gray-600" placeholder="Search for items">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Table -->
                                <div class="overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(0)">
                                                    Product <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(1)">
                                                    Quantity <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(2)">
                                                    Reason <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(3)">
                                                    Date <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="damagedTableBody" class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <?php if (empty($damagedGoods)): ?>
                                                <tr>
                                                    <td colspan="5" class="px-6 py-12 text-center">
                                                        <i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                        <p class="text-gray-500 text-lg">No damaged goods records found.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($damagedGoods as $record): ?>
                                                    <tr class="damaged-row hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" 
                                                        data-product="<?= htmlspecialchars(strtolower($record['product_name'])) ?>"
                                                        data-quantity="<?= $record['quantity'] ?>"
                                                        data-reason="<?= htmlspecialchars(strtolower($record['reason'])) ?>"
                                                        data-date="<?= strtolower(date('Y-m-d H:i', strtotime($record['date']))) ?>">
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($record['product_name']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200"><?= $record['quantity'] ?></td>
                                                        <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-200 max-w-xs">
                                                            <span class="truncate block" title="<?= htmlspecialchars($record['reason']) ?>">
                                                                <?= htmlspecialchars($record['reason']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200"><?= date('M j, Y H:i', strtotime($record['date'])) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                                                            <form method="POST" action="delete_damaged.php" class="inline" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                                                <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                                                <button type="submit" class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800 disabled:opacity-50 disabled:pointer-events-none dark:text-red-500 dark:hover:text-red-400" title="Delete">
                                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                                </button>
                                                            </form>
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
                                            Showing <span id="showingFrom">1</span> to <span id="showingTo"><?= min(10, count($damagedGoods)) ?></span> of <span id="totalRows"><?= count($damagedGoods) ?></span> entries
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
            const tableBody = document.getElementById('damagedTableBody');
            allRows = Array.from(tableBody.querySelectorAll('.damaged-row'));
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
        });

        // Initialize table with pagination
        function initializeTable() {
            filterTable();
        }

        // Filter table based on search
        function filterTable() {
            const searchInput = document.getElementById('hs-table-with-pagination-search');
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';

            filteredRows = allRows.filter(row => {
                const product = row.getAttribute('data-product') || '';
                const reason = row.getAttribute('data-reason') || '';
                const date = row.getAttribute('data-date') || '';

                // Search filter
                const matchesSearch = searchTerm === '' || 
                    product.includes(searchTerm) || 
                    reason.includes(searchTerm) ||
                    date.includes(searchTerm);

                return matchesSearch;
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
                    case 0: // Product
                        aValue = a.getAttribute('data-product') || '';
                        bValue = b.getAttribute('data-product') || '';
                        break;
                    case 1: // Quantity
                        aValue = parseInt(a.getAttribute('data-quantity') || 0);
                        bValue = parseInt(b.getAttribute('data-quantity') || 0);
                        break;
                    case 2: // Reason
                        aValue = a.getAttribute('data-reason') || '';
                        bValue = b.getAttribute('data-reason') || '';
                        break;
                    case 3: // Date
                        aValue = a.getAttribute('data-date') || '';
                        bValue = b.getAttribute('data-date') || '';
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
            const tableBody = document.getElementById('damagedTableBody');
            const totalRows = filteredRows.length;
            const totalPages = Math.ceil(totalRows / rowsPerPage);
            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = startIndex + rowsPerPage;
            const pageRows = filteredRows.slice(startIndex, endIndex);

            // Clear table body
            tableBody.innerHTML = '';

            // Add rows for current page
            if (pageRows.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center"><i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i><p class="text-gray-500 text-lg">No records found matching your criteria.</p></td></tr>';
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

        function checkQuantity(input) {
            const productId = document.querySelector('select[name="product_id"]').value;
            const quantity = input.value;
            const errorMessage = document.getElementById('quantity-error');
            
            // Fetch current stock for selected product
            fetch(`../get_stock.php?product_id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (quantity > data.stock) {
                        errorMessage.classList.remove('hidden');
                        input.setCustomValidity('Quantity exceeds available stock');
                    } else {
                        errorMessage.classList.add('hidden');
                        input.setCustomValidity('');
                    }
                })
                .catch(() => {
                    // If API fails, just hide error message
                    errorMessage.classList.add('hidden');
                    input.setCustomValidity('');
                });
        }

        // Add event listener to product select
        document.addEventListener('DOMContentLoaded', function() {
            const productSelect = document.querySelector('select[name="product_id"]');
            if (productSelect) {
                productSelect.addEventListener('change', function() {
                    const quantityInput = document.querySelector('input[name="quantity"]');
                    if (quantityInput) {
                        checkQuantity(quantityInput);
                    }
                });
            }
        });
    </script>
</body>
</html> 