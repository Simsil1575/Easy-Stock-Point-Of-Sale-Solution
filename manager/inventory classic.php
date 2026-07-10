<?php
$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}
?>

<?php
// New SQLite connection
$mysqli = new PDO('sqlite:../pos.db');
?>


<?php
require_once __DIR__ . '/../ensure_laybye_schema.php';
// Database connection
$db = new PDO('sqlite:../pos.db');

// Fetch products from the database (exclude synthetic lay-bye payment product)
$stmt = $db->query('
    SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
    WHERE ' . laybyePaymentProductWhereExclude('p.name') . '
    GROUP BY p.id
    ORDER BY total_sold DESC
');

$products = [];
$lowStock = [];
$outOfStock = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
    if ($row['quantity'] <= 0) {
        $outOfStock[] = $row;
    } else if ($row['quantity'] < 5) {
        $lowStock[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale System</title>
    <script src="../navigation.js" async></script>
      <link href="../src/output.css" rel="stylesheet">    
      <link rel="icon" href="../favicon.ico" type="image/png">

    <style>
        .cart-item { @apply flex justify-between mb-2 bg-white border border-gray-300; }
    </style>
    <script src="../sweetalert2@11.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const restoreScroll = () => {
                scrollTableToBottom();
            };
            const saveScroll = () => sessionStorage.setItem('scrollPosition', window.scrollY);
            window.addEventListener('beforeunload', saveScroll);
            restoreScroll();

            // Delegated event listener for all forms
            document.body.addEventListener('submit', async (e) => {
                e.preventDefault();
                const form = e.target.closest('form');
                const formData = new FormData(form);
                showLoader();
                
                try {
                    const response = await fetch('inventory_ajax.php', { method: 'POST', body: formData });
                    const data = await response.json();
                    
                    if (data.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: data.message,
                        }).then(() => handleSuccess(formData, data));
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message,
                        });
                    }
                } catch (error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An unexpected error occurred.',
                    });
                } finally {
                    hideLoader();
                }
            });

            const handleSuccess = (formData, data) => {
                const action = formData.get('action');
                const id = formData.get('id');
                
                if (action === 'edit') {
                    const row = document.getElementById(`product-${id}`);
                    if (row) {
                        // Update all fields including image
                        ['name', 'quantity', 'price', 'buying_price'].forEach(field => {
                            row.querySelector(`[name="${field}"]`).value = formData.get(field);
                        });
                        if (data.product?.image_url) {
                            row.querySelector('img').src = `../products/${data.product.image_url}`;
                            row.querySelector('[name="current_image_url"]').value = data.product.image_url;
                        }
                    }
                } else if (action === 'delete') {
                    document.getElementById(`product-${id}`)?.remove();
                } else if (action === 'add') {
                    const tbody = document.getElementById('productTableBody');
                    const { product } = data;
                    const newRow = document.createElement('tr');
                    newRow.className = 'hover:bg-blue-50 transition-colors duration-150 product-row';
                    newRow.id = `product-${product.id}`;
                    newRow.innerHTML = `
                        <td class='py-3 px-4'>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="${product.id}">
                                <input type='text' name='name' value='${escapeHtml(product.name)}' 
                                    class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500'>
                        </td>
                        <td class='py-3 px-4'>
                            <input type='number' name='quantity' value='${product.quantity}' 
                                class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-center'>
                        </td>
                        <td class='py-3 px-4'>
                            <input type='number' name='price' value='${parseFloat(product.price).toFixed(2)}' step='1' 
                                class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-center'>
                        </td>
                        <td class='py-3 px-4'>
                            <input type='number' name='buying_price' value='${product.buying_price != null && product.buying_price !== "" ? parseFloat(product.buying_price).toFixed(2) : ""}' step='0.01' 
                                class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-center'>
                        </td>
                        <td class='py-3 px-4'>
                            <div class="flex flex-col items-center space-y-1">
                                <img src='../products/${escapeHtml(product.image_url)}' alt='Product Image' 
                                    class='w-12 h-12 object-cover rounded shadow product-image-thumbnail'>
                                <input type='file' name='image' 
                                    class='w-full border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500' 
                                    onchange="updateImageThumbnail(this)">
                                <input type='hidden' name='current_image_url' value='${escapeHtml(product.image_url)}'>
                            </div>
                        </td>
                        <td class='py-2 px-4 text-center'>
                            <div class="flex justify-center space-x-2">
                                <button type='submit' class='bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md shadow-sm transition duration-150'>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                <button type='button' onclick="deleteProduct(${product.id})" class='bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-md shadow-sm transition duration-150'>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.707a1 1 0 00-1.414-1.414L10 8.586 7.707 6.293a1 1 0 00-1.414 1.414L8.586 10l-2.293 2.293a1 1 0 001.414 1.414L10 11.414l2.293 2.293a1 1 0 001.414-1.414L11.414 10l2.293-2.293z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                        </form>
                    `;
                    tbody.appendChild(newRow);
                    window.location.reload();
                    scrollTableToBottom();
                    
                }
            };

            const escapeHtml = (text) => {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, m => map[m]);
            };
        });

        function scrollTableToBottom() {
            const tableContainer = document.getElementById('tableContainer');
            tableContainer.scrollTop = tableContainer.scrollHeight;
        }
    </script>
    
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 content"> <!-- Adjusted margin to account for fixed sidebar width -->
            <div class="container mx-auto p-6">
            <div class="flex items-center mb-6">
            <h1 class="text-3xl font-bold mr-4">Inventory Management</h1> <!-- Added margin-right to create space between title and icon -->
            
            <div class="relative cursor-pointer">
                <svg onclick="toggleNotifications()" class="h-6 w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                <?php
                $notificationCount = count($outOfStock) + count($lowStock);
                if ($notificationCount > 0): ?>
                    <span class="absolute top-0 right-0 -mt-2 -mr-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center pointer-events-none"><?= $notificationCount ?></span>
                <?php endif; ?>
     
                    
                    <!-- Notifications Dropdown -->
                    <div id="notificationsDropdown" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl z-50 transform transition-all duration-300 opacity-0 scale-95 border border-gray-100 max-h-[80vh] overflow-y-auto custom-scrollbar">
                        <?php if (empty($outOfStock) && empty($lowStock)): ?>
                            <div class="p-6 text-center">
                                <div class="mx-auto w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                    </svg>
                                </div>
                                <p class="text-gray-500 font-medium">No notifications</p>
                                <p class="text-gray-400 text-sm mt-1">You're all caught up!</p>
                            </div>
                        <?php else: ?>

                            <?php if (!empty($outOfStock)): ?>
                                <div class="p-4 border-b border-gray-100 hover:bg-gray-50 transition-colors duration-200">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0">
                                            <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                                                <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-4 flex-1">
                                            <div class="flex items-center justify-between">
                                                <h3 class="text-sm font-semibold text-gray-900">Out of Stock Products</h3>
                                                <span class="text-xs font-medium text-red-500 bg-red-50 px-2 py-1 rounded-full"><?= count($outOfStock) ?></span>
                                            </div>
                                            <div class="mt-2 space-y-2">
                                                <?php foreach($outOfStock as $product): ?>
                                                    <div class="flex items-center text-sm">
                                                        <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                        </svg>
                                                        <span class="text-gray-700"><?= htmlspecialchars($product['name']) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($lowStock)): ?>
                                <div class="p-4 hover:bg-gray-50 transition-colors duration-200">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0">
                                            <div class="w-10 h-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                                <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-4 flex-1">
                                            <div class="flex items-center justify-between">
                                                <h3 class="text-sm font-semibold text-gray-900">Low Stock Alert</h3>
                                                <span class="text-xs font-medium text-yellow-500 bg-yellow-50 px-2 py-1 rounded-full"><?= count($lowStock) ?></span>
                                            </div>
                                            <div class="mt-2 space-y-2">
                                                <?php foreach($lowStock as $product): ?>
                                                    <div class="flex items-center justify-between text-sm">
                                                        <div class="flex items-center">
                                                            <svg class="w-4 h-4 text-yellow-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                            </svg>
                                                            <span class="text-gray-700"><?= htmlspecialchars($product['name']) ?></span>
                                                        </div>
                                                        <span class="text-yellow-600 font-medium"><?= $product['quantity'] ?> left</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="fixed right-0 top-0 p-4">
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search products..." class="w-64 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <svg class="absolute right-3 top-2.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                    </svg>
                </div>
            </div>
            
                </div>
                <!-- Products Table -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden my-6 max-h-[80vh] overflow-y-auto" id="tableContainer">
                    <table class="min-w-full border-collapse bg-white">
                        <thead>
                            <tr class="bg-gradient-to-r from-gray-700 to-gray-900 text-white text-sm font-semibold uppercase tracking-wider sticky top-0 shadow-lg z-10">
                                <th class="py-4 px-4 text-left border-b">Name</th>
                                <th class="py-4 px-4 text-center border-b w-24">Quantity</th>
                                <th class="py-4 px-4 text-center border-b w-32">Price</th>
                                <th class="py-4 px-4 text-center border-b w-32">Cost of Sale(per unit)</th>
                                <th class="py-4 px-4 text-center border-b w-40">Image</th>
                                <th class="py-4 px-4 text-center border-b w-32">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200" id="productTableBody">
                            <?php
                            // Fetch all products
                            $stmt = $mysqli->query("SELECT * FROM products WHERE " . laybyePaymentProductWhereExclude('name'));
                            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if (count($products) > 0):
                                foreach($products as $row):
                            ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-150 product-row" id="product-<?= $row['id'] ?>" data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <td class='py-3 px-4'><input type='text' name='name' value='<?= htmlspecialchars($row['name']) ?>' class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500'></td>
                                    <td class='py-3 px-4'>
                                        <input type='number' name='quantity' value='<?= htmlspecialchars($row["quantity"]) ?>' class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-center'>
                                    </td>
                                    <td class='py-3 px-4'>
                                        <input type='number' name='price' value='<?= htmlspecialchars($row["price"]) ?>' step='1' class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-center'>
                                    </td>
                                    <td class='py-3 px-4'>
                                        <input type='number' name='buying_price' value='<?= htmlspecialchars($row["buying_price"] ?? '') ?>' step='0.01' class='w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-center'>
                                    </td>
                                    <td class='py-3 px-4'>
                                        <div class="flex flex-col items-center space-y-1">
                                            <img src='../products/<?= htmlspecialchars($row['image_url']) ?>' alt='Product Image' class='w-12 h-12 object-cover rounded shadow product-image-thumbnail'>
                                            <input type='file' name='image' class='w-full border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500' onchange="updateImageThumbnail(this)">
                                            <input type='hidden' name='current_image_url' value='<?= htmlspecialchars($row['image_url']) ?>'>
                                        </div>
                                    </td>
                                    <td class='py-2 px-4 text-center'>
                                        <div class="flex justify-center space-x-2">
                                            <button type='submit' class='bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md shadow-sm transition duration-150'>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                            <button type='button' onclick="deleteProduct(<?= $row['id'] ?>)" class='bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-md shadow-sm transition duration-150'>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.707a1 1 0 00-1.414-1.414L10 8.586 7.707 6.293a1 1 0 00-1.414 1.414L8.586 10l-2.293 2.293a1 1 0 001.414 1.414L10 11.414l2.293 2.293a1 1 0 001.414-1.414L11.414 10l2.293-2.293z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </form>
                            </tr>
                            <?php
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="6" class="py-4 px-6 text-center text-gray-500">No products available</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="add">
                                <tr class="bg-gradient-to-r from-gray-100 to-gray-200 sticky bottom-0 shadow-lg">
                                    <td class='py-3 px-4'><input type="text" name="name" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500" placeholder="New Product Name" required></td>
                                    <td class='py-3 px-4'><input type="number" name="quantity" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500 text-center" placeholder="Qty" required value="20"></td>
                                    <td class='py-3 px-4'><input type="number" name="price" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500 text-center" step="1" placeholder="Price" required value="80"></td>
                                    <td class='py-3 px-4'><input type="number" name="buying_price" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500 text-center" step="0.01" placeholder="Buy Price"></td>
                                    <td class='py-3 px-4'>
                                        <input type="file" name="image" class="w-full border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500" required>
                                    </td>
                                    <td class='py-3 px-4 text-center'>
                                    <button type="submit" class='bg-teal-500 hover:bg-teal-600 text-white px-3 py-1 rounded-md shadow-sm transition duration-150'>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            </form>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php $mysqli = null; ?>
    <script>
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.getElementsByClassName('product-row');
            
            Array.from(rows).forEach(row => {
                const productName = row.getAttribute('data-name').toLowerCase(); // Ensure product name is also in lowercase
                if (productName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>


<script>

    // Add event listener for delete forms
    document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            try {
                const response = await fetch('inventory_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: data.message,
                    })
                } 
            } 
        });
    });
</script>

<script>
    function toggleNotifications() {
        const dropdown = document.getElementById('notificationsDropdown');
        dropdown.classList.toggle('hidden');
        if (!dropdown.classList.contains('hidden')) {
            dropdown.classList.remove('opacity-0', 'scale-95');
            dropdown.classList.add('opacity-100', 'scale-100');
        } else {
            dropdown.classList.add('opacity-0', 'scale-95');
            dropdown.classList.remove('opacity-100', 'scale-100');
        }
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notificationsDropdown');
        const notificationIcon = event.target.closest('svg');
        if (!dropdown.contains(event.target) && !notificationIcon) {
            dropdown.classList.add('hidden', 'opacity-0', 'scale-95');
            dropdown.classList.remove('opacity-100', 'scale-100');
        }
    });
</script>

<script>
    function updateImageThumbnail(input) {
        // Get the parent container of the file input
        const container = input.closest('td');
        
        // Find the image thumbnail element within the container
        const imageThumbnail = container.querySelector('.product-image-thumbnail');
        
        // Check if a file was selected
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            
            // Set up the FileReader to update the image thumbnail
            reader.onload = function (e) {
                imageThumbnail.src = e.target.result; // Update the image source
            };
            
            // Read the selected file as a data URL
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>

<script>
function deleteProduct(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            fetch('inventory_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire('Deleted!', data.message, 'success').then(() => {
                        document.getElementById(`product-${id}`)?.remove();
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            });
        }
    });
}
</script>